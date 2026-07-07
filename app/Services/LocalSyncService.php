<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Order;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use App\Models\SyncState;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class LocalSyncService
{
    public function __construct(private readonly OfflineOutboxService $outbox) {}

    public function run(): array
    {
        $this->assertConfigured();
        $synced = 0;
        $conflicts = 0;

        foreach (SyncOutbox::whereIn('status', ['pending', 'failed'])->orderBy('id')->limit(100)->get() as $event) {
            $response = $this->push($event);
            $event->increment('attempts');

            if ($response->successful()) {
                $event->update(['status' => 'synced', 'synced_at' => now(), 'last_error' => null]);
                $synced++;

                continue;
            }

            $message = $response->json('message') ?: $response->body();
            if ($response->unprocessableEntity() || $response->status() === 409) {
                $event->update(['status' => 'conflict', 'last_error' => $message]);
                SyncConflict::updateOrCreate(
                    ['event_id' => $event->event_id],
                    ['outbox_id' => $event->id, 'event_type' => $event->event_type, 'reason' => mb_substr($message, 0, 255), 'local_payload' => $event->payload, 'remote_response' => $response->json(), 'status' => 'open']
                );
                $conflicts++;

                continue;
            }

            $event->update(['status' => 'failed', 'last_error' => mb_substr($message, 0, 2000)]);

            return $this->status(false, $synced, $conflicts, $message);
        }

        if (SyncOutbox::whereIn('status', ['pending', 'failed', 'conflict'])->exists()) {
            return $this->status(true, $synced, $conflicts, 'Cloud refresh paused until pending events and conflicts are resolved.');
        }

        $products = $this->client()->get($this->url('/api/sync/products'));
        if (! $products->successful()) {
            return $this->status(false, $synced, $conflicts, $products->body());
        }

        $activity = $this->client()->get($this->url('/api/sync/inventory-activity'));
        $activitySync = $activity->successful();

        try {
            $configuration = $this->client()->get($this->url('/api/sync/configuration'));
            $accountSync = $configuration->successful();
        } catch (ConnectionException) {
            $configuration = null;
            $accountSync = false;
        }

        if ($accountSync && data_get($configuration->json(), 'capabilities.device_sync') && ! SyncState::where('key', 'local_device_bootstrap')->exists()) {
            Device::orderBy('id')->each(fn (Device $device) => $this->outbox->queueDevice($device));
            SyncState::create(['key' => 'local_device_bootstrap', 'value' => ['queued_at' => now()->toIso8601String()], 'last_synced_at' => null]);
        }

        try {
            $orders = $this->client()->get($this->url('/api/sync/orders'));
            $orderSync = $orders->successful();
        } catch (ConnectionException) {
            $orders = null;
            $orderSync = false;
        }

        try {
            $attendance = $this->client()->get($this->url('/api/sync/attendance'));
            $attendanceSync = $attendance->successful();
        } catch (ConnectionException) {
            $attendance = null;
            $attendanceSync = false;
        }

        try {
            $payroll = $this->client()->get($this->url('/api/sync/payroll-runs'));
            $payrollSync = $payroll->successful() && is_array($payroll->json());
        } catch (ConnectionException) {
            $payroll = null;
            $payrollSync = false;
        }

        $productPayload = $this->jsonArray($products);
        $configurationPayload = $accountSync ? $this->jsonArray($configuration) : [];
        $orderPayload = $orderSync ? $this->jsonArray($orders) : [];
        $attendancePayload = $attendanceSync ? $this->jsonArray($attendance) : [];
        $payrollPayload = $payrollSync ? $this->jsonArray($payroll) : [];
        $remoteSkus = collect($productPayload)->pluck('sku')->filter()->values();
        if ($remoteSkus->isNotEmpty()) {
            Product::withTrashed()
                ->whereNotIn('sku', $remoteSkus->all())
                ->get()
                ->each(fn (Product $product) => $this->outbox->queueProduct($product));
        }

        try {
            DB::transaction(function () use ($productPayload, $configurationPayload, $accountSync, $orderPayload, $orderSync, $attendancePayload, $attendanceSync, $payrollPayload, $payrollSync) {
                foreach ($productPayload as $remote) {
                    if (! isset($remote['sku'])) {
                        continue;
                    }

                    $product = Product::withTrashed()->firstOrNew(['sku' => $remote['sku']]);
                    $product->fill(collect($remote)->only([
                        'name', 'barcode', 'category', 'supplier', 'unit', 'price', 'discount_percent',
                        'stock_quantity', 'reserved_quantity', 'safety_stock', 'reorder_level', 'version', 'image_url', 'is_active',
                    ])->all());
                    $product->deleted_at = $remote['deleted_at'] ?? null;
                    $product->save();
                }

                if ($accountSync) {
                    $this->applyConfiguration($configurationPayload);
                }
                if ($orderSync) {
                    $this->applyOrders($orderPayload);
                }
                if ($attendanceSync) {
                    $this->applyAttendance($attendancePayload);
                }
                if ($payrollSync) {
                    $this->applyPayrollRuns($payrollPayload);
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->rememberCloudState([
                'products' => count($productPayload),
                'accounts_synced' => false,
                'devices_synced' => false,
                'face_enrollments_synced' => false,
                'activity_synced' => $activitySync,
                'orders_synced' => false,
                'attendance_synced' => false,
                'payroll_synced' => false,
                'last_error' => $exception->getMessage(),
            ]);

            return $this->status(false, $synced, $conflicts, 'Cloud refresh failed while importing data: '.$exception->getMessage());
        }

        $this->rememberCloudState(['products' => count($productPayload), 'accounts_synced' => $accountSync, 'devices_synced' => $accountSync && data_get($configurationPayload, 'capabilities.device_sync', false), 'face_enrollments_synced' => $accountSync && array_key_exists('face_enrollments', $configurationPayload), 'activity_synced' => $activitySync, 'orders_synced' => $orderSync, 'attendance_synced' => $attendanceSync, 'payroll_synced' => $payrollSync, 'last_error' => null]);
        if ($activitySync) {
            SyncState::updateOrCreate(['key' => 'cloud_inventory_activity'], ['value' => ['movements' => $activity->json()], 'last_synced_at' => now()]);
        }

        $message = match (true) {
            ! $accountSync => 'Inventory synced. Deploy the latest cloud release to enable account and workforce synchronization.',
            ! $orderSync => 'Inventory synced. Deploy the latest cloud release to enable order synchronization.',
            ! $attendanceSync => 'Store data synced. Deploy the latest cloud release to enable attendance synchronization.',
            ! $payrollSync => 'Store data synced. Deploy the latest cloud release to enable payroll snapshot synchronization.',
            ! $activitySync => 'Inventory totals synced. Deploy the latest cloud release to enable the shared activity feed.',
            default => null,
        };

        return $this->status(true, $synced, $conflicts, $message);
    }

    public function status(bool $online = true, int $synced = 0, int $conflicts = 0, ?string $message = null): array
    {
        $cloud = SyncState::where('key', 'cloud')->first();
        $cloudValue = $cloud?->value ?? [];

        return [
            'enabled' => (bool) config('offline.enabled'),
            'node_id' => config('offline.node_id'),
            'online' => $online,
            'pending' => SyncOutbox::whereIn('status', ['pending', 'failed'])->count(),
            'conflicts' => SyncConflict::where('status', 'open')->count(),
            'synced_now' => $synced,
            'conflicts_now' => $conflicts,
            'last_synced_at' => $cloud?->last_synced_at,
            'accounts_synced' => (bool) data_get($cloudValue, 'accounts_synced', false),
            'devices_synced' => (bool) data_get($cloudValue, 'devices_synced', false),
            'face_enrollments_synced' => (bool) data_get($cloudValue, 'face_enrollments_synced', false),
            'activity_synced' => (bool) data_get($cloudValue, 'activity_synced', false),
            'orders_synced' => (bool) data_get($cloudValue, 'orders_synced', false),
            'attendance_synced' => (bool) data_get($cloudValue, 'attendance_synced', false),
            'payroll_synced' => (bool) data_get($cloudValue, 'payroll_synced', false),
            'message' => $message ?: data_get($cloudValue, 'last_error'),
        ];
    }

    private function push(SyncOutbox $event): Response
    {
        $path = match ($event->event_type) {
            'sale.completed' => '/api/sync/sales',
            'product.updated' => '/api/sync/products',
            'attendance.recorded' => '/api/sync/attendance',
            'user.account_updated' => '/api/sync/users',
            'employee.updated' => '/api/sync/employees',
            'order.placed' => '/api/sync/orders',
            'order.status_updated' => '/api/sync/order-status',
            'device.updated' => '/api/sync/devices',
            'face.enrollment_updated' => '/api/sync/face-enrollments',
            'payroll.finalized' => '/api/sync/payroll-runs',
            default => throw new RuntimeException("Unsupported sync event {$event->event_type}."),
        };

        return $this->client()->post($this->url($path), [
            'node_id' => config('offline.node_id'),
            'event_id' => $event->event_id,
            'payload' => $event->payload,
        ]);
    }

    private function client()
    {
        return Http::acceptJson()->withToken(config('offline.sync_token'))->timeout(config('offline.timeout'))->retry(2, 500, throw: false);
    }

    private function url(string $path): string
    {
        return config('offline.cloud_url').$path;
    }

    private function assertConfigured(): void
    {
        if (! config('offline.enabled') || ! config('offline.cloud_url') || ! config('offline.sync_token')) {
            throw new RuntimeException('Local offline synchronization is not fully configured.');
        }
    }

    private function applyConfiguration(array $configuration): void
    {
        $remoteUsers = collect($configuration['users'] ?? [])
            ->filter(fn ($remote) => is_array($remote) && isset($remote['email']))
            ->values();

        $remoteUserEmails = $remoteUsers
            ->pluck('email')
            ->filter()
            ->map(fn (string $email) => mb_strtolower($email))
            ->values();

        foreach ($remoteUsers as $remote) {
            $user = User::withTrashed()->where('email', $remote['email'])->first();
            if (! $user && ($remote['erased_identity_hash'] ?? null)) {
                $user = User::withTrashed()->where('erased_identity_hash', $remote['erased_identity_hash'])->first();
            }
            if (! $user && ($remote['erased_identity_hash'] ?? null)) {
                $user = User::withTrashed()->get()->first(fn (User $candidate) => hash('sha256', strtolower($candidate->email)) === $remote['erased_identity_hash']);
            }
            $user ??= User::withTrashed()->firstOrNew(['email' => $remote['email']]);
            $user->forceFill([
                'name' => $remote['name'] ?? $remote['email'],
                'password' => $remote['password_hash'] ?? $user->getRawOriginal('password'),
                'role' => $remote['role'] ?? $user->role ?? 'user',
                'is_active' => $remote['is_active'] ?? true, 'password_changed_at' => $remote['password_changed_at'] ?? null,
                'must_change_password' => $remote['must_change_password'] ?? false,
                'email_verified_at' => $remote['email_verified_at'] ?? null, 'google_id' => $remote['google_id'] ?? null, 'avatar_url' => $remote['avatar_url'] ?? null,
                'email' => $remote['email'], 'erased_identity_hash' => $remote['erased_identity_hash'] ?? null,
            ])->save();
            ($remote['deleted_at'] ?? null) ? $user->delete() : $user->restore();
        }

        if ($remoteUserEmails->isNotEmpty()) {
            User::whereNotIn(DB::raw('LOWER(email)'), $remoteUserEmails->all())
                ->where('email', 'not like', 'deleted-%@anonymized.invalid')
                ->where('role', 'user')
                ->update([
                    'is_active' => false,
                    'must_change_password' => false,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $remoteEmployees = collect($configuration['employees'] ?? [])
            ->filter(fn ($remote) => is_array($remote) && isset($remote['employee_number']))
            ->values();

        $remoteEmployeeNumbers = $remoteEmployees
            ->pluck('employee_number')
            ->filter()
            ->values();

        foreach ($remoteEmployees as $remote) {
            $employee = Employee::withTrashed()->firstOrNew(['employee_number' => $remote['employee_number']]);
            $employee->fill(collect($remote)->except(['employee_number', 'user_email', 'deleted_at'])->all());
            $employee->user_id = isset($remote['user_email']) ? User::where('email', $remote['user_email'])->value('id') : null;
            $employee->save();
            ($remote['deleted_at'] ?? null) ? $employee->delete() : $employee->restore();
        }

        if ($remoteEmployeeNumbers->isNotEmpty()) {
            Employee::whereNotIn('employee_number', $remoteEmployeeNumbers->all())
                ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
        }

        $remoteDevices = collect($configuration['devices'] ?? [])
            ->filter(fn ($remote) => is_array($remote) && isset($remote['name'], $remote['type']))
            ->values();

        $remoteDeviceTokenHashes = $remoteDevices
            ->pluck('token_hash')
            ->filter()
            ->values();
        $remoteDeviceExternalIds = $remoteDevices
            ->pluck('external_id')
            ->filter()
            ->values();

        foreach ($remoteDevices as $remote) {
            $device = null;
            if ($remote['external_id'] ?? null) {
                $device = Device::where('external_id', $remote['external_id'])->first();
            }
            if (! $device && ($remote['token_hash'] ?? null)) {
                $device = Device::where('token_hash', $remote['token_hash'])->first();
            }
            if (! $device && ! ($remote['external_id'] ?? null) && ! ($remote['token_hash'] ?? null)) {
                $device = Device::where('name', $remote['name'])->where('type', $remote['type'])->first();
            }
            $device ??= new Device;
            $device->forceFill([
                'name' => $remote['name'],
                'type' => $remote['type'],
                'location' => $remote['location'] ?? null,
                'provider' => $remote['provider'] ?? null,
                'external_id' => $remote['external_id'] ?? null,
                'token_hash' => $remote['token_hash'] ?? $device->token_hash,
                'configuration' => $remote['configuration'] ?? null,
                'is_active' => $remote['is_active'] ?? true,
                'last_seen_at' => $remote['last_seen_at'] ?? null,
                'created_at' => $remote['created_at'] ?? $device->created_at,
                'updated_at' => $remote['updated_at'] ?? $device->updated_at,
            ])->save();
        }

        if ($remoteDevices->isNotEmpty() && ($remoteDeviceTokenHashes->isNotEmpty() || $remoteDeviceExternalIds->isNotEmpty())) {
            Device::where(function ($query) use ($remoteDeviceTokenHashes, $remoteDeviceExternalIds) {
                if ($remoteDeviceTokenHashes->isNotEmpty()) {
                    $query->orWhere(fn ($inner) => $inner
                        ->whereNotNull('token_hash')
                        ->whereNotIn('token_hash', $remoteDeviceTokenHashes->all()));
                }

                if ($remoteDeviceExternalIds->isNotEmpty()) {
                    $query->orWhere(fn ($inner) => $inner
                        ->whereNotNull('external_id')
                        ->whereNotIn('external_id', $remoteDeviceExternalIds->all()));
                }
            })->update(['is_active' => false, 'updated_at' => now()]);
        }

        $remoteEnrollments = collect($configuration['face_enrollments'] ?? [])
            ->filter(fn ($remote) => is_array($remote) && isset($remote['subject_id'], $remote['descriptors']))
            ->values();
        $remoteEnrollmentSubjects = $remoteEnrollments->pluck('subject_id')->filter()->values();

        foreach ($remoteEnrollments as $remote) {
            $employee = Employee::withTrashed()->where('employee_number', $remote['employee_number'] ?? null)
                ->orWhere('face_subject_id', $remote['subject_id'])->first();
            if (! $employee) {
                continue;
            }

            $device = null;
            if ($remote['device_external_id'] ?? null) {
                $device = Device::where('external_id', $remote['device_external_id'])->first();
            }
            if (! $device && ($remote['device_name'] ?? null)) {
                $device = Device::where('name', $remote['device_name'])
                    ->when($remote['device_type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                    ->first();
            }

            $enrollment = FaceEnrollment::withTrashed()->firstOrNew(['subject_id' => $remote['subject_id']]);
            $enrollment->forceFill([
                'employee_id' => $employee->id,
                'device_id' => $device?->id,
                'employee_name' => $remote['employee_name'] ?? $employee->name,
                'descriptors' => $remote['descriptors'],
                'enrolled_at' => $remote['enrolled_at'] ?? now(),
                'is_active' => $remote['is_active'] ?? true,
                'created_at' => $remote['created_at'] ?? $enrollment->created_at,
                'updated_at' => $remote['updated_at'] ?? $enrollment->updated_at,
            ])->save();
            ($remote['deleted_at'] ?? null) ? $enrollment->delete() : $enrollment->restore();
        }

        if ($remoteEnrollmentSubjects->isNotEmpty()) {
            FaceEnrollment::whereNotIn('subject_id', $remoteEnrollmentSubjects->all())
                ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
        }
    }

    private function jsonArray(?Response $response): array
    {
        $payload = $response?->json();

        return is_array($payload) ? $payload : [];
    }

    private function rememberCloudState(array $value): void
    {
        SyncState::updateOrCreate(['key' => 'cloud'], ['value' => $value, 'last_synced_at' => now()]);
    }

    private function applyOrders(array $orders): void
    {
        foreach ($orders as $remote) {
            $customerId = User::where('email', $remote['customer_email'])->value('id');
            if (! $customerId) {
                throw new RuntimeException("Cannot synchronize order {$remote['reference']}: customer account is missing.");
            }

            $order = Order::firstOrNew(['idempotency_key' => $remote['idempotency_key']]);
            $order->fill(collect($remote)->except(['customer_email', 'items'])->all());
            $order->customer_id = $customerId;
            $order->save();

            $order->items()->delete();
            foreach ($remote['items'] as $item) {
                $productId = Product::withTrashed()->where('sku', $item['sku'])->value('id');
                if (! $productId) {
                    throw new RuntimeException("Cannot synchronize order {$remote['reference']}: product {$item['sku']} is missing.");
                }
                $order->items()->create([...$item, 'product_id' => $productId]);
            }
        }
    }

    private function applyAttendance(array $records): void
    {
        foreach ($records as $remote) {
            $employeeId = Employee::withTrashed()->where('employee_number', $remote['employee_number'])->value('id');
            if (! $employeeId) {
                throw new RuntimeException("Cannot synchronize attendance: employee {$remote['employee_number']} is missing.");
            }

            $deviceId = null;
            if ($remote['device_external_id'] ?? null) {
                $deviceId = Device::where('external_id', $remote['device_external_id'])->value('id');
            } elseif (($remote['device_name'] ?? null) && ($remote['device_type'] ?? null)) {
                $deviceId = Device::where('name', $remote['device_name'])->where('type', $remote['device_type'])->value('id');
            }

            $record = AttendanceRecord::where('employee_id', $employeeId)->whereDate('attendance_date', $remote['attendance_date'])->first();
            if (! $record) {
                AttendanceRecord::create([
                    'employee_id' => $employeeId, 'attendance_date' => $remote['attendance_date'],
                    'device_id' => $deviceId, 'status' => $remote['status'],
                    'recognized_at' => $remote['recognized_at'], 'match_confidence' => $remote['match_confidence'],
                    'provider_event_id' => $remote['provider_event_id'], 'metadata' => $remote['metadata'],
                ]);
            }
        }
    }

    private function applyPayrollRuns(array $runs): void
    {
        foreach ($runs as $remote) {
            $creatorId = User::where('email', $remote['created_by_email'])->value('id');
            if (! $creatorId) {
                throw new RuntimeException("Cannot synchronize payroll {$remote['reference']}: creator account is missing.");
            }
            $run = PayrollRun::where('reference', $remote['reference'])
                ->orWhere(fn ($query) => $query->whereDate('period_start', $remote['period_start'])->whereDate('period_end', $remote['period_end']))->first();
            $run ??= new PayrollRun;
            $run->fill(collect($remote)->only(['reference', 'period_start', 'period_end', 'status', 'finalized_at', 'created_at', 'updated_at'])->all());
            $run->created_by = $creatorId;
            $run->save();
            $run->items()->delete();
            foreach ($remote['items'] as $item) {
                $employeeId = Employee::withTrashed()->where('employee_number', $item['employee_number'])->value('id');
                if (! $employeeId) {
                    throw new RuntimeException("Cannot synchronize payroll {$remote['reference']}: employee {$item['employee_number']} is missing.");
                }
                $run->items()->create(['employee_id' => $employeeId, ...collect($item)->except('employee_number')->all()]);
            }
        }
    }
}
