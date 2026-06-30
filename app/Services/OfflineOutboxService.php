<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Sale;
use App\Models\SyncOutbox;
use Illuminate\Support\Str;

class OfflineOutboxService
{
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
}
