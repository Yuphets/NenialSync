<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Order;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\StatutoryRate;
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
    public function __construct(
        private readonly OfflineOutboxService $outbox,
        private readonly MaintenanceModeService $maintenance,
        private readonly StatutoryRateService $statutoryRates,
        private readonly AccountSessionService $sessions,
        private readonly SyncUserSignatureService $userSignatures,
        private readonly PayrollSnapshotService $payrollSnapshots,
    ) {}

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
                SyncConflict::where('event_id', $event->event_id)
                    ->whereIn('status', ['open', 'retrying'])
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
                $synced++;

                continue;
            }

            $message = $response->json('message') ?: $response->body();
            if ($response->unprocessableEntity() || $response->status() === 409) {
                $event->update(['status' => 'conflict', 'last_error' => $message]);
                SyncConflict::updateOrCreate(
                    ['event_id' => $event->event_id],
                    ['outbox_id' => $event->id, 'event_type' => $event->event_type, 'reason' => mb_substr($message, 0, 255), 'local_payload' => $event->payload, 'remote_response' => $response->json(), 'status' => 'open', 'resolved_at' => null]
                );
                $conflicts++;

                continue;
            }

            $event->update(['status' => 'failed', 'last_error' => mb_substr($message, 0, 2000)]);

            return $this->status(false, $synced, $conflicts, $message);
        }

        if (SyncOutbox::whereIn('status', ['pending', 'failed'])->exists()) {
            return $this->status(true, $synced, $conflicts, 'Cloud refresh paused until the remaining outgoing events are sent.');
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
                ->each(fn (Product $product) => $this->outbox->queueProduct($product, [
                    'version' => 0,
                    'stock_quantity' => 0,
                    'reserved_quantity' => 0,
                    'updated_at' => null,
                ]));
        }

        try {
            DB::transaction(function () use ($productPayload, $configurationPayload, $accountSync, $orderPayload, $orderSync, $attendancePayload, $attendanceSync) {
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
                'statutory_rates_synced' => false,
                'last_error' => $exception->getMessage(),
            ]);

            return $this->status(false, $synced, $conflicts, 'Cloud refresh failed while importing data: '.$exception->getMessage());
        }

        $payrollImportError = null;
        if ($payrollSync) {
            try {
                DB::transaction(fn () => $this->applyPayrollRuns($payrollPayload));
                SyncState::where('key', 'cloud_payroll_conflict')->delete();
            } catch (Throwable $exception) {
                report($exception);
                $payrollSync = false;
                $payrollImportError = $exception->getMessage();
                SyncState::updateOrCreate(
                    ['key' => 'cloud_payroll_conflict'],
                    [
                        'value' => ['message' => $payrollImportError],
                        'last_synced_at' => now(),
                    ]
                );
            }
        }

        $this->rememberCloudState(['products' => count($productPayload), 'accounts_synced' => $accountSync, 'devices_synced' => $accountSync && data_get($configurationPayload, 'capabilities.device_sync', false), 'face_enrollments_synced' => $accountSync && array_key_exists('face_enrollments', $configurationPayload), 'statutory_rates_synced' => $accountSync && data_get($configurationPayload, 'capabilities.statutory_rate_sync', false) && array_key_exists('statutory_rates', $configurationPayload), 'activity_synced' => $activitySync, 'orders_synced' => $orderSync, 'attendance_synced' => $attendanceSync, 'payroll_synced' => $payrollSync, 'last_error' => $payrollImportError]);
        if ($activitySync) {
            SyncState::updateOrCreate(['key' => 'cloud_inventory_activity'], ['value' => ['movements' => $activity->json()], 'last_synced_at' => now()]);
        }

        $message = match (true) {
            $payrollImportError !== null => 'Store data synchronized, but a payroll snapshot needs review: '.$payrollImportError,
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
            'conflicts' => SyncConflict::whereIn('status', ['open', 'retrying'])->count(),
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
            'statutory_rates_synced' => (bool) data_get($cloudValue, 'statutory_rates_synced', false),
            'message' => $message ?: data_get($cloudValue, 'last_error'),
        ];
    }

    public function conflicts(): array
    {
        return SyncConflict::query()
            ->whereIn('status', ['open', 'retrying'])
            ->latest('id')
            ->get()
            ->map(fn (SyncConflict $conflict) => [
                ...$conflict->only([
                    'id', 'event_id', 'event_type', 'reason', 'status',
                    'created_at', 'updated_at',
                ]),
                'local_payload' => $this->redactSyncPayload($conflict->local_payload),
                'remote_response' => $this->redactSyncPayload($conflict->remote_response ?? []),
                'can_retry' => $conflict->outbox_id !== null
                    && SyncOutbox::whereKey($conflict->outbox_id)->exists(),
            ])
            ->all();
    }

    public function resolveConflict(SyncConflict $conflict, string $action): array
    {
        if (! in_array($action, ['retry', 'discard', 'accept_remote'], true)) {
            throw new RuntimeException('Unsupported conflict resolution action.');
        }

        return DB::transaction(function () use ($conflict, $action) {
            $locked = SyncConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            $outbox = $locked->outbox_id
                ? SyncOutbox::query()->lockForUpdate()->find($locked->outbox_id)
                : null;

            if ($action === 'retry') {
                if (! $outbox) {
                    throw new RuntimeException('The original synchronization event no longer exists.');
                }

                $payload = $locked->local_payload;
                if ($outbox->event_type === 'product.updated') {
                    $payload = $this->rebaseProductPayload($payload, $locked->remote_response);
                }

                $outbox->update([
                    'payload' => $payload,
                    'status' => 'pending',
                    'synced_at' => null,
                    'last_error' => null,
                ]);
                $locked->update([
                    'local_payload' => $payload,
                    'status' => 'retrying',
                    'resolved_at' => null,
                ]);
            } else {
                $outbox?->update([
                    'status' => 'discarded',
                    'last_error' => 'Conflict discarded in favor of cloud data.',
                ]);
                $locked->update(['status' => 'resolved', 'resolved_at' => now()]);
            }

            return [
                'conflict_id' => $locked->id,
                'status' => $locked->fresh()->status,
                'outbox_status' => $outbox?->fresh()->status,
            ];
        });
    }

    private function rebaseProductPayload(array $payload, ?array $remoteResponse = null): array
    {
        $sync = $payload['sync'] ?? null;
        if (! is_array($sync) || ! isset($payload['sku'])) {
            return $payload;
        }

        $remote = data_get($remoteResponse, 'remote');
        $remote = is_array($remote)
            && ($remote['sku'] ?? null) === $payload['sku']
            && isset($remote['stock_quantity'], $remote['reserved_quantity'], $remote['version'])
                ? $remote
                : null;
        $product = $remote ? null : Product::withTrashed()->where('sku', $payload['sku'])->first();
        if (! $remote && ! $product) {
            return $payload;
        }

        $baseStock = (int) ($remote['stock_quantity'] ?? $product->stock_quantity);
        $baseReserved = (int) ($remote['reserved_quantity'] ?? $product->reserved_quantity);
        $baseVersion = (int) ($remote['version'] ?? $product->version);
        $baseUpdatedAt = $remote['updated_at'] ?? $product->updated_at?->toIso8601String();
        $stock = $baseStock + (int) ($sync['stock_delta'] ?? 0);
        $reserved = $baseReserved + (int) ($sync['reserved_delta'] ?? 0);
        if ($stock < 0 || $reserved < 0 || $reserved > $stock) {
            throw new RuntimeException('The product conflict cannot be retried because its inventory delta is no longer valid.');
        }

        $payload['stock_quantity'] = $stock;
        $payload['reserved_quantity'] = $reserved;
        $payload['version'] = $baseVersion + 1;
        $payload['updated_at'] = now()->toIso8601String();
        $payload['sync'] = [
            ...$sync,
            'base_version' => $baseVersion,
            'base_updated_at' => $baseUpdatedAt,
            'captured_at' => now()->toIso8601String(),
        ];

        return $payload;
    }

    private function redactSyncPayload(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (in_array($key, ['password_hash', 'token_hash', 'descriptors'], true)) {
                $payload[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = $this->redactSyncPayload($value);
            }
        }

        return $payload;
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
            'system.maintenance_updated' => '/api/sync/maintenance',
            default => throw new RuntimeException("Unsupported sync event {$event->event_type}."),
        };

        $nodeId = (string) config('offline.node_id');
        $payload = $event->payload;
        if ($event->event_type === 'user.account_updated') {
            if (! array_key_exists('sync_version', $payload)) {
                $payload['sync_version'] = (int) (
                    User::withTrashed()->find($event->aggregate_id)?->sync_version ?? 0
                );
            }
            unset($payload['sync_signature']);
            $payload['sync_signature'] = $this->userSignatures->sign(
                $nodeId,
                $event->event_id,
                $payload,
            );
            if ($payload !== $event->payload) {
                $event->update(['payload' => $payload]);
            }
        }

        return $this->client()->post($this->url($path), [
            'node_id' => $nodeId,
            'event_id' => $event->event_id,
            'payload' => $payload,
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
        if (isset($configuration['maintenance']) && is_array($configuration['maintenance'])) {
            $this->maintenance->applyRemote($configuration['maintenance']);
        }
        if (isset($configuration['statutory_rate_monitor']) && is_array($configuration['statutory_rate_monitor'])) {
            $this->statutoryRates->applyRemoteMonitor($configuration['statutory_rate_monitor']);
        }

        if (array_key_exists('statutory_rates', $configuration)) {
            $remoteRates = collect($configuration['statutory_rates'])
                ->filter(fn ($rate) => is_array($rate) && isset($rate['code'], $rate['effective_from'], $rate['rules']))
                ->values();
            $remoteRateKeys = $remoteRates
                ->map(fn (array $rate) => $rate['code'].'|'.$rate['effective_from'])
                ->all();

            foreach ($remoteRates as $remote) {
                $rate = StatutoryRate::query()->firstOrNew([
                    'code' => $remote['code'],
                    'effective_from' => $remote['effective_from'],
                ]);
                $rate->forceFill(collect($remote)->only([
                    'agency', 'revision', 'status', 'effective_to', 'rules',
                    'source_title', 'source_url', 'published_at', 'verified_at',
                    'approved_at', 'rules_checksum', 'created_at', 'updated_at',
                ])->all())->save();
            }

            StatutoryRate::query()->get()
                ->reject(fn (StatutoryRate $rate) => in_array(
                    $rate->code.'|'.$rate->effective_from?->toDateString(),
                    $remoteRateKeys,
                    true,
                ))
                ->each(fn (StatutoryRate $rate) => $rate->update(['status' => 'superseded']));
        }

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
            User::withoutEvents(function () use ($user, $remote): void {
                $user->forceFill([
                    'name' => $remote['name'] ?? $remote['email'],
                    'password' => $remote['password_hash'] ?? $user->getRawOriginal('password'),
                    'role' => $remote['role'] ?? $user->role ?? 'user',
                    'is_active' => $remote['is_active'] ?? true,
                    'password_changed_at' => $remote['password_changed_at'] ?? null,
                    'must_change_password' => $remote['must_change_password'] ?? false,
                    'email_verified_at' => $remote['email_verified_at'] ?? null,
                    'google_id' => $remote['google_id'] ?? null,
                    'avatar_url' => $remote['avatar_url'] ?? null,
                    'email' => $remote['email'],
                    'erased_identity_hash' => $remote['erased_identity_hash'] ?? null,
                    'sync_version' => (int) ($remote['sync_version'] ?? $user->sync_version),
                    'deleted_at' => $remote['deleted_at'] ?? null,
                ])->save();
            });
            if (! $user->is_active || $user->trashed()) {
                $this->sessions->invalidate($user);
            }
        }

        if ($remoteUserEmails->isNotEmpty()) {
            $missingUserIds = User::whereNotIn(DB::raw('LOWER(email)'), $remoteUserEmails->all())
                ->where('email', 'not like', 'deleted-%@anonymized.invalid')
                ->where('role', 'user')
                ->pluck('id');
            User::whereIn('id', $missingUserIds)
                ->update([
                    'is_active' => false,
                    'must_change_password' => false,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
            $missingUserIds->each(fn (int $id) => $this->sessions->invalidate($id));
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
            if (! $employee->is_active || $employee->trashed()) {
                FaceEnrollment::withTrashed()
                    ->where('employee_id', $employee->id)
                    ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            }
        }

        if ($remoteEmployeeNumbers->isNotEmpty()) {
            $missingEmployeeIds = Employee::whereNotIn('employee_number', $remoteEmployeeNumbers->all())
                ->pluck('id');
            Employee::whereIn('id', $missingEmployeeIds)
                ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            FaceEnrollment::withTrashed()
                ->whereIn('employee_id', $missingEmployeeIds)
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
                'is_active' => ($remote['is_active'] ?? true) && $employee->is_active && ! $employee->trashed(),
                'created_at' => $remote['created_at'] ?? $enrollment->created_at,
                'updated_at' => $remote['updated_at'] ?? $enrollment->updated_at,
            ])->save();
            (($remote['deleted_at'] ?? null) || ! $employee->is_active || $employee->trashed())
                ? $enrollment->delete()
                : $enrollment->restore();
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
            $creatorId = User::withTrashed()
                ->where('email', $remote['created_by_account_email'] ?? $remote['created_by_email'])
                ->value('id');
            if (! $creatorId) {
                throw new RuntimeException("Cannot synchronize payroll {$remote['reference']}: creator account is missing.");
            }

            $byReference = PayrollRun::with(['creator', 'items.employee'])
                ->where('reference', $remote['reference'])->first();
            $byPeriod = PayrollRun::with(['creator', 'items.employee'])
                ->where('status', 'finalized')
                ->whereDate('period_start', $remote['period_start'])
                ->whereDate('period_end', $remote['period_end'])->first();
            if ($byReference && $byPeriod && $byReference->id !== $byPeriod->id) {
                throw new RuntimeException(
                    "Cannot synchronize payroll {$remote['reference']}: its reference and period identify different snapshots."
                );
            }

            if ($existing = $byReference ?: $byPeriod) {
                if (! $this->payrollSnapshots->isEquivalent($existing, $remote)) {
                    throw new RuntimeException(
                        "Cannot synchronize payroll {$remote['reference']}: a different finalized snapshot already exists for this period."
                    );
                }

                continue;
            }

            $run = new PayrollRun;
            $run->fill(collect($remote)->only(['reference', 'period_start', 'period_end', 'status', 'finalized_at', 'created_at', 'updated_at'])->all());
            $run->created_by = $creatorId;
            $run->created_by_email = $remote['created_by_email'];
            $run->created_by_name = $remote['created_by_name'] ?? null;
            $run->save();
            foreach ($remote['items'] as $item) {
                $employee = Employee::withTrashed()->where('employee_number', $item['employee_number'])->first();
                if (! $employee) {
                    throw new RuntimeException("Cannot synchronize payroll {$remote['reference']}: employee {$item['employee_number']} is missing.");
                }
                $run->items()->create([
                    'employee_id' => $employee->id,
                    'employee_number' => $item['employee_number'],
                    'employee_name' => $item['employee_name'] ?? $employee->name,
                    'job_title' => $item['job_title'] ?? $employee->job_title,
                    ...collect($item)->except([
                        'employee_number', 'employee_name', 'job_title',
                    ])->all(),
                ]);
            }
        }
    }
}
