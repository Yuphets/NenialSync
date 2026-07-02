<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Sale;
use App\Models\Employee;
use App\Models\Order;
use App\Models\Device;
use App\Models\User;
use App\Models\SyncOutbox;
use Illuminate\Support\Str;

class OfflineOutboxService
{
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
        if (! config('offline.enabled')) return;
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
        if (! config('offline.enabled')) return;
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

    public function queueUser(User $user): void
    {
        $this->queue('user.account_updated', User::class, $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'password_hash' => $user->getRawOriginal('password'),
            'role' => $user->role,
            'is_active' => $user->is_active,
            'password_changed_at' => $user->password_changed_at?->toIso8601String(),
            'must_change_password' => $user->must_change_password,
        ]);
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

    private function queue(string $eventType, string $aggregateType, int $aggregateId, array $payload): void
    {
        if (! config('offline.enabled')) return;
        $pending = SyncOutbox::where('event_type', $eventType)->where('aggregate_type', $aggregateType)
            ->where('aggregate_id', $aggregateId)->whereIn('status', ['pending', 'failed'])->first();
        if ($pending) {
            $pending->update(['payload' => $payload, 'status' => 'pending', 'last_error' => null]);
            return;
        }
        SyncOutbox::create(['event_id' => (string) Str::uuid(), 'event_type' => $eventType, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'payload' => $payload]);
    }
}
