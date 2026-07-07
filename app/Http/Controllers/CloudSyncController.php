<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PayrollRun;
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

    public function orders()
    {
        return Order::with(['customer:id,email', 'items'])
            ->orderBy('id')
            ->get()
            ->map(fn (Order $order) => [
                ...$order->only([
                    'reference', 'idempotency_key', 'status', 'payment_status', 'payment_method',
                    'payment_reference', 'subtotal', 'discount_total', 'vat_rate', 'vatable_sales',
                    'vat_amount', 'total', 'dispatched_at', 'delivered_at', 'received_at',
                    'cancelled_at', 'created_at', 'updated_at',
                ]),
                'customer_email' => $order->customer->email,
                'items' => $order->items->map(fn ($item) => $item->only([
                    'product_name', 'sku', 'quantity', 'unit_price', 'discount_percent',
                    'line_total', 'created_at', 'updated_at',
                ]))->values(),
            ]);
    }

    public function attendances()
    {
        return AttendanceRecord::with(['employee:id,employee_number', 'device:id,name,type,external_id'])
            ->orderBy('id')
            ->get()
            ->map(fn (AttendanceRecord $record) => [
                'employee_number' => $record->employee->employee_number,
                'device_external_id' => $record->device?->external_id,
                'device_name' => $record->device?->name,
                'device_type' => $record->device?->type,
                'attendance_date' => $record->attendance_date->format('Y-m-d'),
                'status' => $record->status,
                'recognized_at' => $record->recognized_at?->toIso8601String(),
                'match_confidence' => $record->match_confidence,
                'provider_event_id' => $record->provider_event_id,
                'metadata' => $record->metadata,
                'created_at' => $record->created_at?->toIso8601String(),
                'updated_at' => $record->updated_at?->toIso8601String(),
            ]);
    }

    public function payrollRuns()
    {
        return PayrollRun::with(['creator:id,email', 'items.employee:id,employee_number'])
            ->orderBy('period_start')->get()->map(fn (PayrollRun $run) => [
                ...$run->only(['reference', 'status', 'finalized_at', 'created_at', 'updated_at']),
                'period_start' => $run->period_start->format('Y-m-d'),
                'period_end' => $run->period_end->format('Y-m-d'),
                'created_by_email' => $run->creator->email,
                'items' => $run->items->map(fn ($item) => [
                    'employee_number' => $item->employee->employee_number,
                    ...$item->only(['base_pay', 'incentive', 'overtime_pay', 'gross_pay', 'sss', 'pagibig', 'philhealth', 'net_pay', 'calculation']),
                ])->values(),
            ]);
    }

    public function configuration()
    {
        return [
            'capabilities' => ['device_sync' => true],
            'users' => User::withTrashed()->orderBy('email')->get()->map(fn (User $user) => [
                'name' => $user->name, 'email' => $user->email,
                'password_hash' => $user->getRawOriginal('password'), 'role' => $user->role,
                'is_active' => $user->is_active, 'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'must_change_password' => $user->must_change_password,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(), 'google_id' => $user->google_id, 'avatar_url' => $user->avatar_url,
                'deleted_at' => $user->deleted_at?->toIso8601String(),
                'erased_identity_hash' => $user->erased_identity_hash,
            ]),
            'employees' => Employee::withTrashed()->with('user:id,email')->orderBy('employee_number')->get()->map(fn (Employee $employee) => [
                ...$employee->only(['employee_number', 'name', 'job_title', 'weekly_salary', 'incentive', 'overtime_hourly_rate', 'overtime_hours', 'deduction_plan', 'face_subject_id', 'is_active']),
                'user_email' => $employee->user?->email, 'deleted_at' => $employee->deleted_at?->toIso8601String(),
            ]),
            'devices' => Device::orderBy('name')->get()->map(fn (Device $device) => [
                ...$device->only(['name', 'type', 'location', 'provider', 'external_id', 'configuration', 'is_active']),
                'token_hash' => $device->getRawOriginal('token_hash'),
                'last_seen_at' => $device->last_seen_at?->toIso8601String(),
                'created_at' => $device->created_at?->toIso8601String(),
                'updated_at' => $device->updated_at?->toIso8601String(),
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

    public function order(Request $request, InventoryService $inventory)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.customer_email' => 'required|email', 'payload.payment_method' => 'required|string|max:40',
            'payload.items' => 'required|array|min:1', 'payload.items.*.sku' => 'required|string|max:80',
            'payload.items.*.quantity' => 'required|integer|min:1',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return Order::with('items', 'customer')->findOrFail($receipt->result_id);
        }

        $customer = User::where('email', $data['payload']['customer_email'])->where('role', 'user')->where('is_active', true)->firstOrFail();
        $products = Product::whereIn('sku', collect($data['payload']['items'])->pluck('sku'))->get()->keyBy('sku');
        abort_unless($products->count() === collect($data['payload']['items'])->pluck('sku')->unique()->count(), 422, 'One or more ordered products no longer exist.');
        $lines = collect($data['payload']['items'])->map(fn ($line) => ['product_id' => $products[$line['sku']]->id, 'quantity' => $line['quantity']])->all();
        $order = $inventory->placeOrder($customer, $lines, $data['payload']['payment_method'], $data['event_id']);
        SyncReceipt::firstOrCreate(
            ['node_id' => $data['node_id'], 'event_id' => $data['event_id']],
            ['event_type' => 'order.placed', 'result_type' => Order::class, 'result_id' => $order->id, 'received_at' => now()]
        );

        return response()->json($order, 201);
    }

    public function orderStatus(Request $request, InventoryService $inventory)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.idempotency_key' => 'required|uuid', 'payload.actor_email' => 'required|email',
            'payload.status' => 'required|in:dispatched,delivered,received,cancelled',
            'payload.dispatched_at' => 'nullable|date', 'payload.delivered_at' => 'nullable|date',
            'payload.received_at' => 'nullable|date', 'payload.cancelled_at' => 'nullable|date',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return Order::with('items', 'customer')->findOrFail($receipt->result_id);
        }

        $payload = $data['payload'];
        $order = Order::where('idempotency_key', $payload['idempotency_key'])->firstOrFail();
        $actor = User::where('email', $payload['actor_email'])->where('is_active', true)->firstOrFail();
        abort_if(in_array($payload['status'], ['dispatched', 'delivered'], true) && ! in_array($actor->role, ['admin', 'assistant'], true), 403, 'Only fulfillment staff can update delivery status.');
        abort_if($payload['status'] === 'cancelled' && $actor->role !== 'admin' && $order->customer_id !== $actor->id, 403, 'Only an administrator or the customer can cancel this order.');
        abort_if($payload['status'] === 'received' && $order->customer_id !== $actor->id, 403, 'Only the customer can confirm receipt.');
        if ($payload['status'] === 'dispatched' && $order->status === 'preparing') {
            $order->update(['status' => 'dispatched', 'dispatched_at' => $payload['dispatched_at'] ?? now()]);
        } elseif ($payload['status'] === 'delivered' && $order->status === 'dispatched') {
            $order->update(['status' => 'delivered', 'delivered_at' => $payload['delivered_at'] ?? now()]);
        } elseif ($payload['status'] === 'cancelled' && in_array($order->status, ['preparing', 'dispatched'], true)) {
            $order = $inventory->cancelOrder($order, $actor);
        } elseif ($payload['status'] === 'received' && $order->status === 'delivered') {
            $order = $inventory->receiveOrder($order, $actor);
        } elseif ($order->status !== $payload['status']) {
            abort(422, "Cloud order is already {$order->status}; cannot apply {$payload['status']}.");
        }

        SyncReceipt::create([
            'node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'order.status_updated',
            'result_type' => Order::class, 'result_id' => $order->id, 'received_at' => now(),
        ]);

        return response()->json($order->fresh(['items', 'customer']), 201);
    }

    public function device(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.name' => 'required|string|max:255',
            'payload.type' => 'required|in:facial,facial_mobile,barcode,pos',
            'payload.location' => 'nullable|string|max:255', 'payload.provider' => 'nullable|string|max:255',
            'payload.external_id' => 'nullable|string|max:255', 'payload.configuration' => 'nullable|array',
            'payload.token_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'payload.is_active' => 'required|boolean',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return Device::findOrFail($receipt->result_id);
        }

        $payload = $data['payload'];
        $identity = $payload['external_id']
            ? ['external_id' => $payload['external_id']]
            : ['name' => $payload['name'], 'type' => $payload['type']];
        $device = Device::updateOrCreate($identity, $payload);
        SyncReceipt::create([
            'node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'device.updated',
            'result_type' => Device::class, 'result_id' => $device->id, 'received_at' => now(),
        ]);

        return response()->json($device, 201);
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
            $record = AttendanceRecord::where('employee_id', $employee->id)
                ->whereDate('attendance_date', $data['payload']['attendance_date'])->first();
            $record ??= AttendanceRecord::create([
                'employee_id' => $employee->id,
                ...collect($data['payload'])->except('employee_number')->all(),
            ]);
            SyncReceipt::create([
                'node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'attendance.recorded',
                'result_type' => AttendanceRecord::class, 'result_id' => $record->id, 'received_at' => now(),
            ]);

            return $record;
        });

        return response()->json($record, 201);
    }

    public function payrollRun(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.reference' => 'required|string|max:255',
            'payload.period_start' => 'required|date', 'payload.period_end' => 'required|date|after_or_equal:payload.period_start',
            'payload.status' => 'required|in:finalized', 'payload.created_by_email' => 'required|email',
            'payload.finalized_at' => 'required|date', 'payload.items' => 'required|array|min:1',
            'payload.items.*.employee_number' => 'required|string',
            'payload.items.*.base_pay' => 'required|numeric|min:0', 'payload.items.*.incentive' => 'required|numeric|min:0',
            'payload.items.*.overtime_pay' => 'required|numeric|min:0', 'payload.items.*.gross_pay' => 'required|numeric|min:0',
            'payload.items.*.sss' => 'required|numeric|min:0', 'payload.items.*.pagibig' => 'required|numeric|min:0',
            'payload.items.*.philhealth' => 'required|numeric|min:0', 'payload.items.*.net_pay' => 'required|numeric|min:0',
            'payload.items.*.calculation' => 'required|array',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return PayrollRun::with('items.employee')->findOrFail($receipt->result_id);
        }

        $run = DB::transaction(function () use ($data) {
            $payload = $data['payload'];
            $creator = User::where('email', $payload['created_by_email'])->whereIn('role', ['admin', 'assistant'])->firstOrFail();
            $run = PayrollRun::where('reference', $payload['reference'])
                ->orWhere(fn ($query) => $query->whereDate('period_start', $payload['period_start'])->whereDate('period_end', $payload['period_end']))->first();
            $run ??= new PayrollRun;
            $run->fill(collect($payload)->only(['reference', 'period_start', 'period_end', 'status', 'finalized_at'])->all());
            $run->created_by = $creator->id;
            $run->save();
            $run->items()->delete();
            foreach ($payload['items'] as $item) {
                $employee = Employee::withTrashed()->where('employee_number', $item['employee_number'])->firstOrFail();
                $run->items()->create(['employee_id' => $employee->id, ...collect($item)->except('employee_number')->all()]);
            }
            SyncReceipt::create(['node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'payroll.finalized', 'result_type' => PayrollRun::class, 'result_id' => $run->id, 'received_at' => now()]);

            return $run;
        });

        return response()->json($run->load('items.employee'), 201);
    }

    public function user(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.name' => 'required|string|max:120', 'payload.email' => 'required|email|max:190',
            'payload.password_hash' => 'required|string|max:255', 'payload.role' => 'required|in:admin,assistant,cashier,user',
            'payload.is_active' => 'required|boolean', 'payload.password_changed_at' => 'nullable|date',
            'payload.must_change_password' => 'required|boolean',
            'payload.email_verified_at' => 'nullable|date', 'payload.google_id' => 'nullable|string|max:255', 'payload.avatar_url' => 'nullable|string|max:2048',
            'payload.deleted_at' => 'nullable|date',
            'payload.erased_identity_hash' => 'nullable|string|size:64', 'payload.lookup_email' => 'nullable|email|max:190',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return User::findOrFail($receipt->result_id);
        }
        $user = DB::transaction(function () use ($data) {
            $payload = $data['payload'];
            $user = User::withTrashed()->where('email', $payload['lookup_email'] ?? $payload['email'])
                ->orWhere('erased_identity_hash', $payload['erased_identity_hash'] ?? '__none__')->first()
                ?: new User;
            $user->forceFill([
                'name' => $payload['name'], 'password' => $payload['password_hash'], 'role' => $payload['role'],
                'is_active' => $payload['is_active'], 'password_changed_at' => $payload['password_changed_at'],
                'must_change_password' => $payload['must_change_password'],
                'email_verified_at' => $payload['email_verified_at'], 'google_id' => $payload['google_id'], 'avatar_url' => $payload['avatar_url'],
                'email' => $payload['email'], 'erased_identity_hash' => $payload['erased_identity_hash'] ?? null,
            ])->save();
            $payload['deleted_at'] ? $user->delete() : $user->restore();
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
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return Employee::withTrashed()->findOrFail($receipt->result_id);
        }
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
