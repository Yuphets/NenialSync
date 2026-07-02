<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Device;
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

    public function inventoryActivity()
    {
        return DB::table('inventory_movements')
            ->latest()
            ->limit(100)
            ->get([
                'id', 'product_id', 'type', 'quantity_delta', 'reserved_delta',
                'stock_before', 'stock_after', 'reserved_before', 'reserved_after',
                'reason', 'idempotency_key', 'created_at', 'updated_at',
            ]);
    }

    public function configuration()
    {
        return [
            'users' => User::orderBy('email')->get()->map(fn (User $user) => [
                'name' => $user->name, 'email' => $user->email,
                'password_hash' => $user->getRawOriginal('password'), 'role' => $user->role,
                'is_active' => $user->is_active, 'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'must_change_password' => $user->must_change_password,
            ]),
            'employees' => Employee::withTrashed()->with('user:id,email')->orderBy('employee_number')->get()->map(fn (Employee $employee) => [
                ...$employee->only(['employee_number', 'name', 'job_title', 'weekly_salary', 'incentive', 'overtime_hourly_rate', 'overtime_hours', 'deduction_plan', 'face_subject_id', 'is_active']),
                'user_email' => $employee->user?->email, 'deleted_at' => $employee->deleted_at?->toIso8601String(),
            ]),
            'devices' => Device::orderBy('name')->get()->map(fn (Device $device) => [
                ...$device->only(['name', 'type', 'location', 'provider', 'external_id', 'configuration', 'is_active']),
                'token_hash' => $device->getRawOriginal('token_hash'),
            ]),
        ];
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
            'payload.vat_rate' => 'nullable|numeric|min:0|max:1',
            'payload.vatable_sales' => 'nullable|numeric|min:0',
            'payload.vat_amount' => 'nullable|numeric|min:0',
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

    public function user(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.name' => 'required|string|max:120', 'payload.email' => 'required|email|max:190',
            'payload.password_hash' => 'required|string|max:255', 'payload.role' => 'required|in:admin,assistant,cashier,user',
            'payload.is_active' => 'required|boolean', 'payload.password_changed_at' => 'nullable|date',
            'payload.must_change_password' => 'required|boolean',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) return User::findOrFail($receipt->result_id);
        $user = DB::transaction(function () use ($data) {
            $payload = $data['payload'];
            $user = User::firstOrNew(['email' => $payload['email']]);
            $user->forceFill([
                'name' => $payload['name'], 'password' => $payload['password_hash'], 'role' => $payload['role'],
                'is_active' => $payload['is_active'], 'password_changed_at' => $payload['password_changed_at'],
                'must_change_password' => $payload['must_change_password'],
            ])->save();
            SyncReceipt::create(['node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'user.account_updated', 'result_type' => User::class, 'result_id' => $user->id, 'received_at' => now()]);
            return $user;
        });
        return response()->json($user, 201);
    }

    public function employee(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.employee_number' => 'required|string|max:40', 'payload.user_email' => 'nullable|email',
            'payload.name' => 'required|string|max:190', 'payload.job_title' => 'required|string|max:120',
            'payload.weekly_salary' => 'required|numeric|min:0', 'payload.incentive' => 'required|numeric|min:0',
            'payload.overtime_hourly_rate' => 'required|numeric|min:0', 'payload.overtime_hours' => 'required|numeric|min:0',
            'payload.deduction_plan' => 'nullable|array', 'payload.deduction_plan.*' => 'in:sss,pagibig,philhealth',
            'payload.face_subject_id' => 'nullable|string|max:190', 'payload.is_active' => 'required|boolean', 'payload.deleted_at' => 'nullable|date',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) return Employee::withTrashed()->findOrFail($receipt->result_id);
        $employee = DB::transaction(function () use ($data) {
            $payload = $data['payload'];
            $employee = Employee::withTrashed()->firstOrNew(['employee_number' => $payload['employee_number']]);
            $employee->fill(collect($payload)->except(['employee_number', 'user_email', 'deleted_at'])->all());
            $employee->user_id = isset($payload['user_email']) ? User::where('email', $payload['user_email'])->value('id') : null;
            $employee->save();
            $payload['deleted_at'] ? $employee->delete() : $employee->restore();
            SyncReceipt::create(['node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'employee.updated', 'result_type' => Employee::class, 'result_id' => $employee->id, 'received_at' => now()]);
            return $employee;
        });
        return response()->json($employee, 201);
    }
}
