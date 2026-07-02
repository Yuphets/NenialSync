<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Order;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use App\Models\SyncState;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        DB::transaction(function () use ($products, $configuration, $accountSync, $orders, $orderSync, $attendance, $attendanceSync) {
            foreach ($products->json() as $remote) {
                $product = Product::withTrashed()->firstOrNew(['sku' => $remote['sku']]);
                $product->fill(collect($remote)->only([
                    'name', 'barcode', 'category', 'supplier', 'unit', 'price', 'discount_percent',
                    'stock_quantity', 'reserved_quantity', 'safety_stock', 'reorder_level', 'version', 'image_url', 'is_active',
                ])->all());
                $product->deleted_at = $remote['deleted_at'];
                $product->save();
            }

            if ($accountSync) $this->applyConfiguration($configuration->json());
            if ($orderSync) $this->applyOrders($orders->json());
            if ($attendanceSync) $this->applyAttendance($attendance->json());
        });

        SyncState::updateOrCreate(['key' => 'cloud'], ['value' => ['products' => count($products->json()), 'accounts_synced' => $accountSync, 'activity_synced' => $activitySync, 'orders_synced' => $orderSync, 'attendance_synced' => $attendanceSync], 'last_synced_at' => now()]);
        if ($activitySync) {
            SyncState::updateOrCreate(['key' => 'cloud_inventory_activity'], ['value' => ['movements' => $activity->json()], 'last_synced_at' => now()]);
        }

        $message = match (true) {
            ! $accountSync => 'Inventory synced. Deploy the latest cloud release to enable account and workforce synchronization.',
            ! $orderSync => 'Inventory synced. Deploy the latest cloud release to enable order synchronization.',
            ! $attendanceSync => 'Store data synced. Deploy the latest cloud release to enable attendance synchronization.',
            ! $activitySync => 'Inventory totals synced. Deploy the latest cloud release to enable the shared activity feed.',
            default => null,
        };

        return $this->status(true, $synced, $conflicts, $message);
    }

    public function status(bool $online = true, int $synced = 0, int $conflicts = 0, ?string $message = null): array
    {
        return [
            'enabled' => (bool) config('offline.enabled'),
            'node_id' => config('offline.node_id'),
            'online' => $online,
            'pending' => SyncOutbox::whereIn('status', ['pending', 'failed'])->count(),
            'conflicts' => SyncConflict::where('status', 'open')->count(),
            'synced_now' => $synced,
            'conflicts_now' => $conflicts,
            'last_synced_at' => SyncState::where('key', 'cloud')->value('last_synced_at'),
            'accounts_synced' => (bool) data_get(SyncState::where('key', 'cloud')->first()?->value, 'accounts_synced', false),
            'activity_synced' => (bool) data_get(SyncState::where('key', 'cloud')->first()?->value, 'activity_synced', false),
            'orders_synced' => (bool) data_get(SyncState::where('key', 'cloud')->first()?->value, 'orders_synced', false),
            'attendance_synced' => (bool) data_get(SyncState::where('key', 'cloud')->first()?->value, 'attendance_synced', false),
            'message' => $message,
        ];
    }

    private function push(SyncOutbox $event): Response
    {
        $path = match ($event->event_type) {
            'sale.completed' => '/api/sync/sales',
            'attendance.recorded' => '/api/sync/attendance',
            'user.account_updated' => '/api/sync/users',
            'employee.updated' => '/api/sync/employees',
            'order.placed' => '/api/sync/orders',
            'order.status_updated' => '/api/sync/order-status',
            'device.updated' => '/api/sync/devices',
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
        foreach ($configuration['users'] ?? [] as $remote) {
            $user = User::firstOrNew(['email' => $remote['email']]);
            $user->forceFill([
                'name' => $remote['name'], 'password' => $remote['password_hash'], 'role' => $remote['role'],
                'is_active' => $remote['is_active'], 'password_changed_at' => $remote['password_changed_at'],
                'must_change_password' => $remote['must_change_password'] ?? false,
            ])->save();
        }
        foreach ($configuration['employees'] ?? [] as $remote) {
            $employee = Employee::withTrashed()->firstOrNew(['employee_number' => $remote['employee_number']]);
            $employee->fill(collect($remote)->except(['employee_number', 'user_email', 'deleted_at'])->all());
            $employee->user_id = isset($remote['user_email']) ? User::where('email', $remote['user_email'])->value('id') : null;
            $employee->save();
            $remote['deleted_at'] ? $employee->delete() : $employee->restore();
        }
        foreach ($configuration['devices'] ?? [] as $remote) {
            $identity = $remote['external_id'] ? ['external_id' => $remote['external_id']] : ['name' => $remote['name'], 'type' => $remote['type']];
            Device::updateOrCreate($identity, collect($remote)->except(['external_id'])->all());
        }
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

            AttendanceRecord::updateOrCreate(
                ['employee_id' => $employeeId, 'attendance_date' => $remote['attendance_date']],
                [
                    'device_id' => $deviceId, 'status' => $remote['status'],
                    'recognized_at' => $remote['recognized_at'], 'match_confidence' => $remote['match_confidence'],
                    'provider_event_id' => $remote['provider_event_id'], 'metadata' => $remote['metadata'],
                ]
            );
        }
    }
}
