<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Order;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SyncOutbox;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Str;

class OfflineOutboxService
{
    public function __construct(
        private readonly PayrollSnapshotService $payrollSnapshots,
    ) {}

    public function queueMaintenance(array $status, User $actor): void
    {
        $this->queue('system.maintenance_updated', SystemSetting::class, 1, [
            'enabled' => (bool) ($status['enabled'] ?? false),
            'message' => $status['message'] ?? MaintenanceModeService::DEFAULT_MESSAGE,
            'started_at' => $status['started_at'] ?? null,
            'updated_at' => $status['updated_at'] ?? now()->toIso8601String(),
            'authorized_by_email' => $actor->email,
        ]);
    }

    /**
     * Queue a product mutation with enough context for the cloud to reconcile
     * inventory without trusting a stale absolute stock snapshot.
     *
     * @param  array{version?: int, stock_quantity?: int, reserved_quantity?: int, updated_at?: mixed, deleted_at?: mixed}|null  $base
     */
    public function queueProduct(Product $product, ?array $base = null): void
    {
        if (! config('offline.enabled')) {
            return;
        }

        $payload = [
            ...$product->only([
                'name', 'sku', 'barcode', 'category', 'supplier', 'unit', 'price',
                'discount_percent', 'stock_quantity', 'reserved_quantity', 'safety_stock',
                'reorder_level', 'version', 'image_url', 'is_active',
            ]),
            'deleted_at' => $product->deleted_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
        ];

        if ($base !== null) {
            $metadataFields = [
                'name', 'sku', 'barcode', 'category', 'supplier', 'unit', 'price',
                'discount_percent', 'safety_stock', 'reorder_level', 'image_url',
                'is_active', 'deleted_at',
            ];
            $payload['sync'] = [
                'base_version' => max(0, (int) ($base['version'] ?? 0)),
                'base_updated_at' => $this->dateString($base['updated_at'] ?? null),
                'stock_delta' => (int) $product->stock_quantity - (int) ($base['stock_quantity'] ?? 0),
                'reserved_delta' => (int) $product->reserved_quantity - (int) ($base['reserved_quantity'] ?? 0),
                'metadata_changed' => collect($metadataFields)->contains(
                    fn (string $field) => $this->comparable($payload[$field] ?? null) !== $this->comparable($base[$field] ?? null)
                ),
                'captured_at' => now()->toIso8601String(),
            ];
        }

        $pending = SyncOutbox::where('event_type', 'product.updated')
            ->where('aggregate_type', Product::class)
            ->where('aggregate_id', $product->id)
            ->whereIn('status', ['pending', 'failed'])
            ->first();

        if (! $pending) {
            SyncOutbox::create([
                'event_id' => (string) Str::uuid(),
                'event_type' => 'product.updated',
                'aggregate_type' => Product::class,
                'aggregate_id' => $product->id,
                'payload' => $payload,
            ]);

            return;
        }

        $previous = $pending->payload;
        if (isset($previous['sync']) && is_array($previous['sync'])) {
            $previousSync = $previous['sync'];
            $nextSync = $payload['sync'] ?? [];
            $payload['sync'] = [
                'base_version' => max(0, (int) ($previousSync['base_version'] ?? 0)),
                'base_updated_at' => $previousSync['base_updated_at'] ?? null,
                'stock_delta' => (int) ($previousSync['stock_delta'] ?? 0)
                    + (int) ($nextSync['stock_delta'] ?? ((int) $payload['stock_quantity'] - (int) ($previous['stock_quantity'] ?? 0))),
                'reserved_delta' => (int) ($previousSync['reserved_delta'] ?? 0)
                    + (int) ($nextSync['reserved_delta'] ?? ((int) $payload['reserved_quantity'] - (int) ($previous['reserved_quantity'] ?? 0))),
                'metadata_changed' => (bool) ($previousSync['metadata_changed'] ?? true)
                    || (bool) ($nextSync['metadata_changed'] ?? true),
                'captured_at' => $nextSync['captured_at'] ?? now()->toIso8601String(),
            ];
        } elseif (isset($payload['sync'])) {
            // A pre-upgrade event has no trustworthy base version. Keep it in
            // legacy snapshot mode so the cloud can reject unsafe stock drift.
            unset($payload['sync']);
        }

        $pending->update(['payload' => $payload, 'status' => 'pending', 'last_error' => null]);
    }

    public function queueDevice(Device $device): void
    {
        $this->queue('device.updated', Device::class, $device->id, [
            'name' => $device->name,
            'type' => $device->type,
            'location' => $device->location,
            'provider' => $device->provider,
            'external_id' => $device->external_id,
            'configuration' => $device->configuration,
            'token_hash' => $device->getRawOriginal('token_hash'),
            'is_active' => $device->is_active,
        ]);
    }

    public function queueOrderPlaced(Order $order): void
    {
        if (! config('offline.enabled')) {
            return;
        }
        $order->loadMissing('items', 'customer');
        SyncOutbox::firstOrCreate(
            ['event_type' => 'order.placed', 'aggregate_type' => Order::class, 'aggregate_id' => $order->id],
            [
                'event_id' => $order->idempotency_key,
                'payload' => [
                    'customer_email' => $order->customer->email,
                    'payment_method' => $order->payment_method,
                    'items' => $order->items->map(fn ($item) => ['sku' => $item->sku, 'quantity' => $item->quantity])->values()->all(),
                ],
            ]
        );
    }

    public function queueOrderStatus(Order $order, User $actor): void
    {
        if (! config('offline.enabled')) {
            return;
        }
        SyncOutbox::create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.status_updated',
            'aggregate_type' => Order::class,
            'aggregate_id' => $order->id,
            'payload' => [
                'idempotency_key' => $order->idempotency_key,
                'actor_email' => $actor->email,
                'status' => $order->status,
                'dispatched_at' => $order->dispatched_at?->toIso8601String(),
                'delivered_at' => $order->delivered_at?->toIso8601String(),
                'received_at' => $order->received_at?->toIso8601String(),
                'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            ],
        ]);
    }

    public function queueUser(User $user, ?string $lookupEmail = null, User|string|null $authorizedBy = null): void
    {
        if (! config('offline.enabled')) {
            return;
        }

        $authorizedByEmail = $authorizedBy instanceof User ? $authorizedBy->email : $authorizedBy;

        $payload = [
            'name' => $user->name,
            'email' => $user->email,
            'password_hash' => $user->getRawOriginal('password'),
            'role' => $user->role,
            'is_active' => $user->is_active,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'must_change_password' => $user->must_change_password,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'google_id' => $user->google_id,
            'avatar_url' => $user->avatar_url,
            'deleted_at' => $user->deleted_at?->toIso8601String(),
            'erased_identity_hash' => $user->erased_identity_hash,
            'lookup_email' => $lookupEmail ?: $user->email,
            'authorized_by_email' => $authorizedByEmail,
            'sync_version' => (int) $user->sync_version,
        ];

        $this->queue('user.account_updated', User::class, $user->id, $payload);
    }

    public function queueEmployee(Employee $employee): void
    {
        $employee->loadMissing('user');
        $this->queue('employee.updated', Employee::class, $employee->id, [
            'employee_number' => $employee->employee_number,
            'user_email' => $employee->user?->email,
            'name' => $employee->name,
            'job_title' => $employee->job_title,
            'weekly_salary' => (float) $employee->weekly_salary,
            'incentive' => (float) $employee->incentive,
            'overtime_hourly_rate' => (float) $employee->overtime_hourly_rate,
            'overtime_hours' => (float) $employee->overtime_hours,
            'deduction_plan' => $employee->deduction_plan,
            'face_subject_id' => $employee->face_subject_id,
            'is_active' => $employee->is_active,
            'deleted_at' => $employee->deleted_at?->toIso8601String(),
        ]);
    }

    public function queueFaceEnrollment(FaceEnrollment $enrollment): void
    {
        $enrollment->loadMissing('employee', 'device');
        $this->queue('face.enrollment_updated', FaceEnrollment::class, $enrollment->id, [
            'employee_number' => $enrollment->employee?->employee_number,
            'device_external_id' => $enrollment->device?->external_id,
            'device_name' => $enrollment->device?->name,
            'device_type' => $enrollment->device?->type,
            'subject_id' => $enrollment->subject_id,
            'employee_name' => $enrollment->employee_name,
            'descriptors' => $enrollment->descriptors,
            'enrolled_at' => $enrollment->enrolled_at?->toIso8601String(),
            'is_active' => $enrollment->is_active,
            'deleted_at' => $enrollment->deleted_at?->toIso8601String(),
        ]);
    }

    public function queueSale(Sale $sale): void
    {
        if (! config('offline.enabled')) {
            return;
        }

        $sale->loadMissing('items', 'cashier');
        SyncOutbox::firstOrCreate(
            ['event_type' => 'sale.completed', 'aggregate_type' => Sale::class, 'aggregate_id' => $sale->id],
            [
                'event_id' => $sale->idempotency_key,
                'payload' => [
                    'reference' => $sale->reference,
                    'cashier_email' => $sale->cashier->email,
                    'payment_method' => $sale->payment_method,
                    'subtotal' => (float) $sale->subtotal,
                    'discount_total' => (float) $sale->discount_total,
                    'vat_rate' => (float) $sale->vat_rate,
                    'vatable_sales' => (float) $sale->vatable_sales,
                    'vat_amount' => (float) $sale->vat_amount,
                    'total' => (float) $sale->total,
                    'completed_at' => $sale->completed_at->toIso8601String(),
                    'items' => $sale->items->map(fn ($item) => [
                        'sku' => $item->sku,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->unit_price,
                        'discount_percent' => (float) $item->discount_percent,
                        'line_total' => (float) $item->line_total,
                    ])->values()->all(),
                ],
            ]
        );
    }

    public function queueAttendance(AttendanceRecord $record): void
    {
        if (! config('offline.enabled')) {
            return;
        }

        $record->loadMissing('employee');
        $payload = [
            'employee_number' => $record->employee->employee_number,
            'attendance_date' => $record->attendance_date,
            'status' => $record->status,
            'recognized_at' => $record->recognized_at?->toIso8601String(),
            'match_confidence' => $record->match_confidence,
            'provider_event_id' => $record->provider_event_id,
            'metadata' => $record->metadata,
        ];
        $pending = SyncOutbox::where('event_type', 'attendance.recorded')
            ->where('aggregate_type', AttendanceRecord::class)
            ->where('aggregate_id', $record->id)
            ->whereIn('status', ['pending', 'failed'])
            ->first();

        if ($pending) {
            $pending->update(['payload' => $payload, 'status' => 'pending', 'last_error' => null]);
        } else {
            SyncOutbox::create([
                'event_id' => (string) Str::uuid(), 'event_type' => 'attendance.recorded',
                'aggregate_type' => AttendanceRecord::class, 'aggregate_id' => $record->id, 'payload' => $payload,
            ]);
        }
    }

    public function queuePayrollRun(PayrollRun $run): void
    {
        $this->queue(
            'payroll.finalized',
            PayrollRun::class,
            $run->id,
            $this->payrollSnapshots->payload($run)
        );
    }

    private function queue(string $eventType, string $aggregateType, int $aggregateId, array $payload): void
    {
        if (! config('offline.enabled')) {
            return;
        }
        $pending = SyncOutbox::where('event_type', $eventType)->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)->whereIn('status', ['pending', 'failed'])->first();
        if ($pending) {
            $pending->update(['payload' => $payload, 'status' => 'pending', 'last_error' => null]);

            return;
        }
        SyncOutbox::create(['event_id' => (string) Str::uuid(), 'event_type' => $eventType, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'payload' => $payload]);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (string) $value;
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return is_numeric($value) ? (string) $value : $value;
    }
}
