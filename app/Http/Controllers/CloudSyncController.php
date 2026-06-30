<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SyncReceipt;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudSyncController extends Controller
{
    public function products()
    {
        return Product::withTrashed()->orderBy('sku')->get();
    }

    public function sale(Request $request, InventoryService $inventory)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80',
            'event_id' => 'required|uuid',
            'payload.reference' => 'required|string|max:255',
            'payload.cashier_email' => 'required|email',
            'payload.payment_method' => 'required|string|max:40',
            'payload.subtotal' => 'required|numeric|min:0',
            'payload.discount_total' => 'required|numeric|min:0',
            'payload.total' => 'required|numeric|min:0',
            'payload.completed_at' => 'required|date',
            'payload.items' => 'required|array|min:1',
            'payload.items.*.sku' => 'required|string',
            'payload.items.*.product_name' => 'required|string',
            'payload.items.*.quantity' => 'required|integer|min:1',
            'payload.items.*.unit_price' => 'required|numeric|min:0',
            'payload.items.*.discount_percent' => 'required|numeric|min:0|max:100',
            'payload.items.*.line_total' => 'required|numeric|min:0',
        ]);

        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return Sale::with('items', 'cashier')->findOrFail($receipt->result_id);
        }

        $cashier = User::where('email', $data['payload']['cashier_email'])->whereIn('role', ['admin', 'cashier'])->where('is_active', true)->firstOrFail();
        $sale = $inventory->importOfflineSale($cashier, $data['payload'], $data['event_id'], $data['node_id']);
        SyncReceipt::firstOrCreate(
            ['node_id' => $data['node_id'], 'event_id' => $data['event_id']],
            ['event_type' => 'sale.completed', 'result_type' => Sale::class, 'result_id' => $sale->id, 'received_at' => now()]
        );

        return response()->json($sale, 201);
    }

    public function attendance(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80',
            'event_id' => 'required|uuid',
            'payload.employee_number' => 'required|string',
            'payload.attendance_date' => 'required|date',
            'payload.status' => 'required|in:present,absent,half_day,leave',
            'payload.recognized_at' => 'nullable|date',
            'payload.match_confidence' => 'nullable|numeric|min:0|max:100',
            'payload.provider_event_id' => 'nullable|string',
            'payload.metadata' => 'nullable|array',
        ]);

        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return AttendanceRecord::findOrFail($receipt->result_id);
        }

        $record = DB::transaction(function () use ($data) {
            $employee = Employee::where('employee_number', $data['payload']['employee_number'])->where('is_active', true)->firstOrFail();
            $record = AttendanceRecord::updateOrCreate(
                ['employee_id' => $employee->id, 'attendance_date' => $data['payload']['attendance_date']],
                collect($data['payload'])->except('employee_number')->all()
            );
            SyncReceipt::create([
                'node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'attendance.recorded',
                'result_type' => AttendanceRecord::class, 'result_id' => $record->id, 'received_at' => now(),
            ]);

            return $record;
        });

        return response()->json($record, 201);
    }
}
