<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Order;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PayrollCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OperationsController extends Controller
{
    public function dashboard(Request $r)
    {
        if ($r->user()->role === 'user') {
            return [
                'products' => Product::where('is_active', true)->count(),
                'low_stock' => 0,
                'sales_today' => 0,
                'orders_pending' => Order::where('customer_id', $r->user()->id)->whereIn('status', ['preparing', 'dispatched', 'delivered'])->count(),
                'employees' => 0,
                'latest_movements' => [],
                'customer_view' => true,
            ];
        }

        return ['products' => Product::count(), 'low_stock' => Product::get()->where('is_low_stock', true)->count(), 'sales_today' => (float) Sale::whereDate('completed_at', today())->sum('total'), 'orders_pending' => Order::whereIn('status', ['preparing', 'dispatched', 'delivered'])->count(), 'employees' => Employee::where('is_active', true)->count(), 'latest_movements' => DB::table('inventory_movements')->latest()->limit(12)->get()];
    }

    public function pos(Request $r, InventoryService $s)
    {
        abort_unless($r->user()->isOneOf('admin', 'cashier'), 403);
        $d = $r->validate(['items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1', 'payment_method' => 'required|string|max:40', 'discount_percent' => 'nullable|numeric|min:0|max:100', 'idempotency_key' => 'required|uuid']);

        return response()->json($s->completeSale($r->user(), $d['items'], $d['payment_method'], (float) ($d['discount_percent'] ?? 0), $d['idempotency_key']), 201);
    }

    public function sales()
    {
        return Sale::with('items', 'cashier:id,name')->latest()->paginate(50);
    }

    public function orders(Request $r)
    {
        $q = Order::with('items', 'customer:id,name,email')->latest();
        if ($r->user()->role === 'user') {
            $q->where('customer_id', $r->user()->id);
        }

        return $q->paginate(50);
    }

    public function placeOrder(Request $r, InventoryService $s)
    {
        abort_unless($r->user()->role === 'user', 403);
        $d = $r->validate(['items' => 'required|array|min:1', 'items.*.product_id' => 'required|integer', 'items.*.quantity' => 'required|integer|min:1', 'payment_method' => 'required|string|max:40', 'idempotency_key' => 'required|uuid']);

        return response()->json($s->placeOrder($r->user(), $d['items'], $d['payment_method'], $d['idempotency_key']), 201);
    }

    public function orderStatus(Request $r, Order $order)
    {
        abort_unless($r->user()->isOneOf('admin', 'assistant'), 403);
        $d = $r->validate(['status' => 'required|in:dispatched,delivered']);
        if ($d['status'] === 'dispatched' && $order->status !== 'preparing') {
            abort(422, 'Order must be preparing.');
        }if ($d['status'] === 'delivered' && $order->status !== 'dispatched') {
            abort(422, 'Order must be dispatched.');
        }$order->update(['status' => $d['status'], $d['status'].'_at' => now()]);

        return $order->fresh(['items', 'customer']);
    }

    public function receive(Request $r, Order $order, InventoryService $s)
    {
        return $s->receiveOrder($order, $r->user());
    }

    public function cancel(Request $r, Order $order, InventoryService $s)
    {
        abort_unless($r->user()->role === 'admin' || $order->customer_id === $r->user()->id, 403);

        return $s->cancelOrder($order, $r->user());
    }

    public function employees()
    {
        return Employee::where('is_active', true)->orderBy('name')->get();
    }

    public function employeeStore(Request $r)
    {
        abort_unless($r->user()->isOneOf('admin', 'assistant'), 403);

        return response()->json(Employee::create($this->employeeData($r)), 201);
    }

    public function employeeUpdate(Request $r, Employee $employee)
    {
        abort_unless($r->user()->isOneOf('admin', 'assistant'), 403);
        $employee->update($this->employeeData($r, $employee));

        return $employee->fresh();
    }

    public function employeeDestroy(Request $r, Employee $employee)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $employee->update(['is_active' => false]);
        $employee->delete();

        return response()->noContent();
    }

    public function attendance(Request $r)
    {
        $q = AttendanceRecord::with('employee', 'device')->latest('attendance_date');
        if ($from = $r->date('from')) {
            $q->whereDate('attendance_date', '>=', $from);
        }if ($to = $r->date('to')) {
            $q->whereDate('attendance_date', '<=', $to);
        }

        return $q->paginate(100);
    }

    public function attendanceStore(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['employee_id' => 'required|exists:employees,id', 'attendance_date' => 'required|date', 'status' => 'required|in:present,absent,half_day,leave', 'recognized_at' => 'nullable|date', 'match_confidence' => 'nullable|numeric|min:0|max:100']);

        return AttendanceRecord::updateOrCreate(['employee_id' => $d['employee_id'], 'attendance_date' => $d['attendance_date']], $d);
    }

    public function payrollPreview(PayrollCalculator $calc)
    {
        return Employee::where('is_active', true)->get()->map(fn ($e) => ['employee' => $e, 'calculation' => $calc->calculate($e)]);
    }

    public function payrollRun(Request $r, PayrollCalculator $calc)
    {
        abort_unless($r->user()->isOneOf('admin', 'assistant'), 403);
        $d = $r->validate(['period_start' => 'required|date', 'period_end' => 'required|date|after_or_equal:period_start']);

        return DB::transaction(function () use ($r, $d, $calc) {
            $run = PayrollRun::create(['reference' => 'PAY-'.now()->format('YmdHis'), 'period_start' => $d['period_start'], 'period_end' => $d['period_end'], 'status' => 'finalized', 'created_by' => $r->user()->id, 'finalized_at' => now()]);
            foreach (Employee::where('is_active', true)->get() as $e) {
                $values = $calc->calculate($e);
                $run->items()->create([...$values, 'employee_id' => $e->id, 'calculation' => $values]);
            }

            return $run->load('items.employee');
        });
    }

    public function users(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return User::orderBy('name')->get();
    }

    public function userRole(Request $r, User $user)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['role' => 'required|in:admin,assistant,cashier,user']);
        $user->update($d);

        return $user;
    }

    public function userDestroy(Request $r, User $user)
    {
        abort_unless($r->user()->role === 'admin' && $user->id !== $r->user()->id, 403);
        abort_if($user->role === 'admin' && User::where('role', 'admin')->where('is_active', true)->count() === 1, 422, 'Final admin cannot be removed.');
        $user->update(['is_active' => false]);

        return response()->noContent();
    }

    public function report(Request $r)
    {
        abort_unless($r->user()->isOneOf('admin', 'assistant'), 403);
        $from = $r->date('from') ?: now()->startOfMonth();
        $to = $r->date('to') ?: now()->endOfMonth();

        return ['range' => [$from, $to], 'sales' => ['total' => (float) Sale::whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])->sum('total'), 'count' => Sale::whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])->count()], 'inventory' => Product::orderBy('name')->get(), 'orders' => Order::whereBetween('created_at', [$from->startOfDay(), $to->endOfDay()])->selectRaw('status,count(*) as count,sum(total) as total')->groupBy('status')->get(), 'attendance' => AttendanceRecord::whereBetween('attendance_date', [$from, $to])->selectRaw('status,count(*) as count')->groupBy('status')->get(), 'payroll' => (float) PayrollItem::whereHas('payrollRun', fn ($q) => $q->whereBetween('period_start', [$from, $to]))->sum('net_pay')];
    }

    public function devices(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);

        return Device::orderBy('name')->get();
    }

    public function deviceStore(Request $r)
    {
        abort_unless($r->user()->role === 'admin', 403);
        $d = $r->validate(['name' => 'required|string', 'type' => 'required|in:facial,barcode,pos', 'location' => 'nullable|string', 'provider' => 'nullable|string', 'external_id' => 'nullable|string|unique:devices', 'configuration' => 'nullable|array']);
        $token = Str::random(64);
        $device = Device::create([...$d, 'token_hash' => hash('sha256', $token), 'is_active' => true]);

        return response()->json(['device' => $device, 'token' => $token], 201);
    }

    public function deviceAttendance(Request $r)
    {
        $device = $r->attributes->get('device');
        abort_unless($device->type === 'facial', 422);
        $d = $r->validate(['subject_id' => 'required|string', 'event_id' => 'required|string', 'recognized_at' => 'required|date', 'confidence' => 'required|numeric|min:0|max:100', 'status' => 'nullable|in:present,half_day']);
        $employee = Employee::where('face_subject_id', $d['subject_id'])->where('is_active', true)->firstOrFail();
        $at = Carbon::parse($d['recognized_at']);
        $record = AttendanceRecord::updateOrCreate(['employee_id' => $employee->id, 'attendance_date' => $at->toDateString()], ['device_id' => $device->id, 'status' => $d['status'] ?? 'present', 'recognized_at' => $at, 'match_confidence' => $d['confidence'], 'provider_event_id' => $d['event_id'], 'metadata' => $r->except(['subject_id'])]);

        return response()->json($record, 201);
    }

    private function employeeData(Request $r, ?Employee $e = null)
    {
        return $r->validate(['employee_number' => 'required|string|max:40|unique:employees,employee_number,'.($e?->id ?? 'NULL'), 'name' => 'required|string|max:190', 'job_title' => 'required|string|max:120', 'weekly_salary' => 'required|numeric|min:0', 'incentive' => 'nullable|numeric|min:0', 'overtime_hourly_rate' => 'nullable|numeric|min:0', 'overtime_hours' => 'nullable|numeric|min:0', 'deduction_plan' => 'nullable|array', 'deduction_plan.*' => 'in:sss,pagibig,philhealth', 'face_subject_id' => 'nullable|string|max:190|unique:employees,face_subject_id,'.($e?->id ?? 'NULL')]);
    }
}
