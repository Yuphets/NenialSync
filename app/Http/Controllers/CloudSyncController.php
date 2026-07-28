<?php

namespace App\Http\Controllers;

use App\Models\AttendanceRecord;
use App\Models\Device;
use App\Models\Employee;
use App\Models\FaceEnrollment;
use App\Models\Order;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StatutoryRate;
use App\Models\SyncReceipt;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AccountSessionService;
use App\Services\InventoryService;
use App\Services\MaintenanceModeService;
use App\Services\PayrollSnapshotService;
use App\Services\StatutoryRateService;
use App\Services\SyncUserSignatureService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudSyncController extends Controller
{
    public function products()
    {
        return Product::withTrashed()->orderBy('sku')->get();
    }

    public function product(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80',
            'event_id' => 'required|uuid',
            'payload.name' => 'required|string|max:190',
            'payload.sku' => 'required|string|max:80',
            'payload.barcode' => 'required|string|max:120',
            'payload.category' => 'required|string|max:80',
            'payload.supplier' => 'nullable|string|max:190',
            'payload.unit' => 'required|string|max:32',
            'payload.price' => 'required|numeric|min:0',
            'payload.discount_percent' => 'nullable|numeric|min:0|max:100',
            'payload.stock_quantity' => 'required|integer|min:0',
            'payload.reserved_quantity' => 'required|integer|min:0',
            'payload.safety_stock' => 'required|integer|min:0',
            'payload.reorder_level' => 'required|integer|min:0',
            'payload.version' => 'required|integer|min:1',
            'payload.image_url' => 'nullable|string|max:2048',
            'payload.is_active' => 'required|boolean',
            'payload.deleted_at' => 'nullable|date',
            'payload.updated_at' => 'nullable|date',
            'payload.sync' => 'sometimes|array',
            'payload.sync.base_version' => 'required_with:payload.sync|integer|min:0',
            'payload.sync.base_updated_at' => 'nullable|date',
            'payload.sync.stock_delta' => 'required_with:payload.sync|integer',
            'payload.sync.reserved_delta' => 'required_with:payload.sync|integer',
            'payload.sync.metadata_changed' => 'required_with:payload.sync|boolean',
            'payload.sync.captured_at' => 'nullable|date',
        ]);

        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return Product::withTrashed()->findOrFail($receipt->result_id);
        }

        $result = DB::transaction(function () use ($data) {
            $payload = $data['payload'];
            $product = Product::withTrashed()
                ->where(fn ($query) => $query
                    ->where('sku', $payload['sku'])
                    ->orWhere('barcode', $payload['barcode']))
                ->lockForUpdate()
                ->first();

            if ($product) {
                $conflict = $this->productConflict($product, $payload);
                if ($conflict) {
                    return ['conflict' => $conflict];
                }

                $sync = $payload['sync'] ?? null;
                $metadataChanged = ! is_array($sync) || (bool) $sync['metadata_changed'];
                $stock = is_array($sync)
                    ? (int) $product->stock_quantity + (int) $sync['stock_delta']
                    : (int) $product->stock_quantity;
                $reserved = is_array($sync)
                    ? (int) $product->reserved_quantity + (int) $sync['reserved_delta']
                    : (int) $product->reserved_quantity;

                if ($stock < 0 || $reserved < 0 || $reserved > $stock) {
                    return ['conflict' => [
                        'message' => 'The inventory delta is no longer valid against current cloud stock.',
                        'code' => 'invalid_inventory_delta',
                    ]];
                }

                $attributes = [
                    'stock_quantity' => $stock,
                    'reserved_quantity' => $reserved,
                    'version' => max((int) $product->version + 1, (int) $payload['version']),
                ];
                if ($metadataChanged) {
                    $attributes = [
                        ...$attributes,
                        ...collect($payload)->only([
                            'name', 'sku', 'barcode', 'category', 'supplier', 'unit',
                            'price', 'discount_percent', 'safety_stock', 'reorder_level',
                            'image_url', 'is_active',
                        ])->all(),
                    ];
                }
                $product->forceFill($attributes)->save();
            } else {
                $product = new Product;
                $product->forceFill(collect($payload)->only([
                    'name', 'sku', 'barcode', 'category', 'supplier', 'unit', 'price',
                    'discount_percent', 'stock_quantity', 'reserved_quantity',
                    'safety_stock', 'reorder_level', 'version', 'image_url', 'is_active',
                ])->all())->save();
            }

            ($payload['deleted_at'] ?? null) ? $product->delete() : $product->restore();
            SyncReceipt::create([
                'node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'product.updated',
                'result_type' => Product::class, 'result_id' => $product->id, 'received_at' => now(),
            ]);

            return ['product' => $product];
        });

        if (isset($result['conflict'])) {
            return response()->json([
                ...$result['conflict'],
                'sku' => $data['payload']['sku'],
                'base_version' => data_get($data, 'payload.sync.base_version'),
                'remote' => Product::withTrashed()->where('sku', $data['payload']['sku'])->first(),
            ], 409);
        }

        return response()->json($result['product'], 201);
    }

    private function productConflict(Product $product, array $payload): ?array
    {
        $sync = $payload['sync'] ?? null;
        if (! is_array($sync)) {
            if ((int) $payload['stock_quantity'] !== (int) $product->stock_quantity
                || (int) $payload['reserved_quantity'] !== (int) $product->reserved_quantity) {
                return [
                    'message' => 'Legacy product snapshots cannot replace current cloud inventory. Refresh the local store and retry the adjustment.',
                    'code' => 'unsafe_legacy_inventory_snapshot',
                    'current_version' => (int) $product->version,
                ];
            }

            if ((int) $payload['version'] < (int) $product->version) {
                return [
                    'message' => 'The product was changed in the cloud after this local snapshot was captured.',
                    'code' => 'stale_product_version',
                    'current_version' => (int) $product->version,
                ];
            }

            return null;
        }

        if ((int) $sync['base_version'] === (int) $product->version) {
            return null;
        }

        if (! (bool) $sync['metadata_changed']) {
            // Pure stock deltas are safe to rebase on the current locked row.
            return null;
        }

        return [
            'message' => 'The product details changed in the cloud after this local edit. Refresh the local store, then retry or discard the conflict.',
            'code' => 'stale_product_version',
            'current_version' => (int) $product->version,
        ];
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

    public function payrollRuns(PayrollSnapshotService $snapshots)
    {
        return PayrollRun::with(['creator:id,email,name', 'items.employee:id,employee_number,name,job_title'])
            ->where('status', 'finalized')
            ->orderBy('period_start')->get()->map(fn (PayrollRun $run) => [
                ...$snapshots->payload($run),
                'created_at' => $run->created_at?->toIso8601String(),
                'updated_at' => $run->updated_at?->toIso8601String(),
            ]);
    }

    public function configuration()
    {
        return [
            'capabilities' => ['device_sync' => true, 'maintenance_sync' => true, 'statutory_rate_sync' => true],
            'maintenance' => app(MaintenanceModeService::class)->status(),
            'statutory_rate_monitor' => app(StatutoryRateService::class)->monitorStatus(),
            'statutory_rates' => StatutoryRate::query()
                ->orderBy('code')
                ->orderBy('effective_from')
                ->get()
                ->map(fn (StatutoryRate $rate) => [
                    ...$rate->only([
                        'code', 'agency', 'revision', 'status', 'rules',
                        'source_title', 'source_url', 'rules_checksum',
                    ]),
                    'effective_from' => $rate->effective_from?->toDateString(),
                    'effective_to' => $rate->effective_to?->toDateString(),
                    'published_at' => $rate->published_at?->toDateString(),
                    'verified_at' => $rate->verified_at?->toIso8601String(),
                    'approved_at' => $rate->approved_at?->toIso8601String(),
                    'created_at' => $rate->created_at?->toIso8601String(),
                    'updated_at' => $rate->updated_at?->toIso8601String(),
                ]),
            'users' => User::withTrashed()->orderBy('email')->get()->map(fn (User $user) => [
                'name' => $user->name, 'email' => $user->email,
                'password_hash' => $user->getRawOriginal('password'), 'role' => $user->role,
                'is_active' => $user->is_active, 'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'must_change_password' => $user->must_change_password,
                'email_verified_at' => $user->email_verified_at?->toIso8601String(), 'google_id' => $user->google_id, 'avatar_url' => $user->avatar_url,
                'deleted_at' => $user->deleted_at?->toIso8601String(),
                'erased_identity_hash' => $user->erased_identity_hash,
                'sync_version' => (int) $user->sync_version,
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
            'face_enrollments' => FaceEnrollment::withTrashed()->with(['employee:id,employee_number', 'device:id,name,type,external_id'])
                ->orderBy('subject_id')->get()->map(fn (FaceEnrollment $enrollment) => [
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
                    'created_at' => $enrollment->created_at?->toIso8601String(),
                    'updated_at' => $enrollment->updated_at?->toIso8601String(),
                ]),
        ];
    }

    public function maintenance(Request $request, MaintenanceModeService $maintenance)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80',
            'event_id' => 'required|uuid',
            'payload.enabled' => 'required|boolean',
            'payload.message' => 'nullable|string|max:240',
            'payload.started_at' => 'nullable|date',
            'payload.updated_at' => 'nullable|date',
            'payload.authorized_by_email' => 'required|email',
        ]);

        if (SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->exists()) {
            return $maintenance->status();
        }

        $actor = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($data['payload']['authorized_by_email'])])
            ->where('role', 'admin')
            ->where('is_active', true)
            ->first();
        abort_unless($actor, 422, 'The administrator who authorized this maintenance change is not active on the cloud site.');

        $status = DB::transaction(function () use ($data, $maintenance, $actor) {
            $status = $maintenance->applyRemote($data['payload'], $actor);
            $setting = SystemSetting::where('key', MaintenanceModeService::KEY)->firstOrFail();
            SyncReceipt::create([
                'node_id' => $data['node_id'],
                'event_id' => $data['event_id'],
                'event_type' => 'system.maintenance_updated',
                'result_type' => SystemSetting::class,
                'result_id' => $setting->id,
                'received_at' => now(),
            ]);

            return $status;
        });

        return response()->json($status, 201);
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
            'payload.amount_tendered' => 'nullable|numeric|min:0',
            'payload.change_due' => 'nullable|numeric|min:0',
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

    public function payrollRun(Request $request, PayrollSnapshotService $snapshots)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.reference' => 'required|string|max:255',
            'payload.period_start' => 'required|date', 'payload.period_end' => 'required|date|after_or_equal:payload.period_start',
            'payload.status' => 'required|in:finalized', 'payload.created_by_email' => 'required|email',
            'payload.created_by_name' => 'nullable|string|max:190',
            'payload.created_by_account_email' => 'nullable|email|max:190',
            'payload.finalized_at' => 'required|date', 'payload.items' => 'required|array|min:1',
            'payload.items.*.employee_number' => 'required|string|distinct',
            'payload.items.*.employee_name' => 'nullable|string|max:190',
            'payload.items.*.job_title' => 'nullable|string|max:120',
            'payload.items.*.base_pay' => 'required|numeric|min:0', 'payload.items.*.incentive' => 'required|numeric|min:0',
            'payload.items.*.overtime_pay' => 'required|numeric|min:0', 'payload.items.*.gross_pay' => 'required|numeric|min:0',
            'payload.items.*.sss' => 'required|numeric|min:0', 'payload.items.*.pagibig' => 'required|numeric|min:0',
            'payload.items.*.philhealth' => 'required|numeric|min:0', 'payload.items.*.net_pay' => 'required|numeric|min:0',
            'payload.items.*.other_deductions' => 'sometimes|numeric|min:0',
            'payload.items.*.calculation' => 'required|array',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            $run = PayrollRun::with(['creator', 'items.employee'])->findOrFail($receipt->result_id);
            abort_unless(
                $snapshots->isEquivalent($run, $data['payload']),
                409,
                'This sync event was already received with a different finalized payroll payload.'
            );

            return $run;
        }

        $byReference = PayrollRun::with(['creator', 'items.employee'])
            ->where('reference', $data['payload']['reference'])->first();
        $byPeriod = PayrollRun::with(['creator', 'items.employee'])
            ->where('status', 'finalized')
            ->whereDate('period_start', $data['payload']['period_start'])
            ->whereDate('period_end', $data['payload']['period_end'])->first();
        abort_if(
            $byReference && $byPeriod && $byReference->id !== $byPeriod->id,
            409,
            'The payroll reference and payroll period identify different finalized snapshots.'
        );

        if ($existing = $byReference ?: $byPeriod) {
            abort_unless(
                $snapshots->isEquivalent($existing, $data['payload']),
                409,
                'A different finalized payroll snapshot already exists for this reference or period.'
            );
            DB::transaction(function () use ($data, $existing): void {
                SyncReceipt::create([
                    'node_id' => $data['node_id'],
                    'event_id' => $data['event_id'],
                    'event_type' => 'payroll.finalized',
                    'result_type' => PayrollRun::class,
                    'result_id' => $existing->id,
                    'received_at' => now(),
                ]);
            });

            return $existing;
        }

        try {
            $run = DB::transaction(function () use ($data) {
                $payload = $data['payload'];
                $creator = User::withTrashed()
                    ->where('email', $payload['created_by_account_email'] ?? $payload['created_by_email'])
                    ->whereIn('role', ['admin', 'assistant'])
                    ->firstOrFail();
                $run = PayrollRun::create([
                    ...collect($payload)->only([
                        'reference', 'period_start', 'period_end', 'status', 'finalized_at',
                    ])->all(),
                    'created_by' => $creator->id,
                    'created_by_email' => $payload['created_by_email'],
                    'created_by_name' => $payload['created_by_name'] ?? $creator->name,
                ]);
                foreach ($payload['items'] as $item) {
                    $employee = Employee::withTrashed()->where('employee_number', $item['employee_number'])->firstOrFail();
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
                SyncReceipt::create(['node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'payroll.finalized', 'result_type' => PayrollRun::class, 'result_id' => $run->id, 'received_at' => now()]);

                return $run;
            });
        } catch (QueryException $exception) {
            if (PayrollRun::where('status', 'finalized')
                ->whereDate('period_start', $data['payload']['period_start'])
                ->whereDate('period_end', $data['payload']['period_end'])
                ->exists()) {
                return response()->json([
                    'message' => 'A finalized payroll snapshot already exists for this period.',
                ], 409);
            }

            throw $exception;
        }

        return response()->json($run->load('items.employee'), 201);
    }

    public function user(
        Request $request,
        AccountSessionService $sessions,
        SyncUserSignatureService $signatures,
    ) {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.name' => 'required|string|max:120', 'payload.email' => 'required|email|max:190',
            'payload.password_hash' => 'required|string|max:255', 'payload.role' => 'required|in:admin,assistant,cashier,user',
            'payload.is_active' => 'required|boolean', 'payload.password_changed_at' => 'nullable|date',
            'payload.must_change_password' => 'required|boolean',
            'payload.email_verified_at' => 'nullable|date', 'payload.google_id' => 'nullable|string|max:255', 'payload.avatar_url' => 'nullable|string|max:2048',
            'payload.deleted_at' => 'nullable|date',
            'payload.erased_identity_hash' => 'nullable|string|size:64', 'payload.lookup_email' => 'nullable|email|max:190',
            'payload.authorized_by_email' => 'nullable|email|max:190',
            'payload.sync_version' => 'required|integer|min:0',
            'payload.sync_signature' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ]);
        $this->assertTrustedSyncNode($data['node_id']);
        $unsignedPayload = $data['payload'];
        $signature = $unsignedPayload['sync_signature'];
        unset($unsignedPayload['sync_signature']);
        abort_unless(
            $signatures->verify(
                $data['node_id'],
                $data['event_id'],
                $unsignedPayload,
                $signature,
            ),
            403,
            'The account synchronization signature is invalid.'
        );
        $data['payload'] = $unsignedPayload;

        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return User::withTrashed()->findOrFail($receipt->result_id);
        }

        $user = DB::transaction(function () use ($data, $sessions) {
            $payload = $data['payload'];
            $lookupEmail = mb_strtolower($payload['lookup_email'] ?? $payload['email']);
            $user = User::withTrashed()
                ->where(function ($query) use ($lookupEmail, $payload) {
                    $query->whereRaw('LOWER(email) = ?', [$lookupEmail]);
                    if ($payload['erased_identity_hash'] ?? null) {
                        $query->orWhere('erased_identity_hash', $payload['erased_identity_hash']);
                    }
                })
                ->lockForUpdate()
                ->first();
            $existing = (bool) $user;
            $user ??= new User;
            abort_if(
                $existing && (int) $payload['sync_version'] < (int) $user->sync_version,
                409,
                'This account synchronization update is older than the current cloud account state.'
            );

            $incomingDeleted = filled($payload['deleted_at'] ?? null);
            $incomingActive = (bool) $payload['is_active'];
            $actor = null;
            if ($payload['authorized_by_email'] ?? null) {
                $actor = User::query()
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($payload['authorized_by_email'])])
                    ->where('is_active', true)
                    ->first();
            }

            $roleChanged = $existing && $user->role !== $payload['role'];
            $accessChanged = $existing && (
                (bool) $user->is_active !== $incomingActive
                || $user->trashed() !== $incomingDeleted
            );
            $emailChanged = $existing && mb_strtolower($user->email) !== mb_strtolower($payload['email']);
            $requiresAdministrator = (! $existing && $payload['role'] !== 'user')
                || $roleChanged
                || $accessChanged
                || $emailChanged;

            if ($requiresAdministrator) {
                abort_unless(
                    $actor && $actor->role === 'admin',
                    422,
                    'An active administrator must authorize synchronized role, access, or staff-account changes.'
                );
            }

            if ($existing && in_array($user->role, ['admin', 'assistant', 'cashier'], true)) {
                $passwordChanged = ! hash_equals(
                    (string) $user->getRawOriginal('password'),
                    (string) $payload['password_hash']
                );
                $identityChanged = $passwordChanged
                    || (string) $user->google_id !== (string) ($payload['google_id'] ?? null)
                    || (string) $user->erased_identity_hash !== (string) ($payload['erased_identity_hash'] ?? null);
                $authorizedSelf = $actor && $actor->id === $user->id;
                $authorizedAdmin = $actor && $actor->role === 'admin';

                abort_unless(
                    ! $identityChanged || $authorizedSelf || $authorizedAdmin,
                    422,
                    'An active administrator or the affected staff member must authorize synchronized security changes.'
                );
            }

            $removesActiveAdmin = $existing
                && $user->role === 'admin'
                && $user->is_active
                && ($payload['role'] !== 'admin' || ! $incomingActive || $incomingDeleted);
            if ($removesActiveAdmin) {
                abort_unless(
                    User::query()
                        ->where('role', 'admin')
                        ->where('is_active', true)
                        ->whereKeyNot($user->id)
                        ->exists(),
                    422,
                    'At least one active administrator must remain.'
                );
            }

            User::withoutEvents(function () use ($user, $payload, $incomingDeleted): void {
                $user->forceFill([
                    'name' => $payload['name'],
                    'password' => $payload['password_hash'],
                    'role' => $payload['role'],
                    'is_active' => $payload['is_active'],
                    'password_changed_at' => $payload['password_changed_at'],
                    'must_change_password' => $payload['must_change_password'],
                    'email_verified_at' => $payload['email_verified_at'],
                    'google_id' => $payload['google_id'],
                    'avatar_url' => $payload['avatar_url'],
                    'email' => $payload['email'],
                    'erased_identity_hash' => $payload['erased_identity_hash'] ?? null,
                    'sync_version' => (int) $payload['sync_version'],
                    'deleted_at' => $incomingDeleted ? $payload['deleted_at'] : null,
                ])->save();
            });
            if (! $incomingActive || $incomingDeleted) {
                $sessions->invalidate($user);
            }
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
            if (! $employee->is_active || $employee->trashed()) {
                FaceEnrollment::withTrashed()
                    ->where('employee_id', $employee->id)
                    ->update(['is_active' => false, 'deleted_at' => now(), 'updated_at' => now()]);
            }
            SyncReceipt::create(['node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'employee.updated', 'result_type' => Employee::class, 'result_id' => $employee->id, 'received_at' => now()]);

            return $employee;
        });

        return response()->json($employee, 201);
    }

    public function faceEnrollment(Request $request)
    {
        $data = $request->validate([
            'node_id' => 'required|string|max:80', 'event_id' => 'required|uuid',
            'payload.employee_number' => 'required|string|max:40',
            'payload.device_external_id' => 'nullable|string|max:255',
            'payload.device_name' => 'nullable|string|max:255',
            'payload.device_type' => 'nullable|in:facial,facial_mobile,barcode,pos',
            'payload.subject_id' => 'required|string|max:190',
            'payload.employee_name' => 'required|string|max:190',
            'payload.descriptors' => 'required|array|min:3|max:8',
            'payload.descriptors.*' => 'required|array|size:128',
            'payload.descriptors.*.*' => 'numeric',
            'payload.enrolled_at' => 'nullable|date',
            'payload.is_active' => 'required|boolean',
            'payload.deleted_at' => 'nullable|date',
        ]);
        if ($receipt = SyncReceipt::where('node_id', $data['node_id'])->where('event_id', $data['event_id'])->first()) {
            return FaceEnrollment::withTrashed()->findOrFail($receipt->result_id);
        }

        $enrollment = DB::transaction(function () use ($data) {
            $payload = $data['payload'];
            $employee = Employee::withTrashed()->where('employee_number', $payload['employee_number'])->firstOrFail();
            abort_if(
                $payload['is_active'] && (! $employee->is_active || $employee->trashed()),
                422,
                'A face enrollment cannot be activated for an inactive employee.'
            );
            $device = null;
            if ($payload['device_external_id'] ?? null) {
                $device = Device::where('external_id', $payload['device_external_id'])->first();
            }
            if (! $device && ($payload['device_name'] ?? null)) {
                $device = Device::where('name', $payload['device_name'])
                    ->when($payload['device_type'] ?? null, fn ($query, $type) => $query->where('type', $type))
                    ->first();
            }

            $enrollment = FaceEnrollment::withTrashed()->firstOrNew(['subject_id' => $payload['subject_id']]);
            $enrollment->forceFill([
                'employee_id' => $employee->id,
                'device_id' => $device?->id,
                'employee_name' => $payload['employee_name'],
                'descriptors' => $payload['descriptors'],
                'enrolled_at' => $payload['enrolled_at'] ?? now(),
                'is_active' => $payload['is_active'],
            ])->save();
            $payload['deleted_at'] ? $enrollment->delete() : $enrollment->restore();
            SyncReceipt::create(['node_id' => $data['node_id'], 'event_id' => $data['event_id'], 'event_type' => 'face.enrollment_updated', 'result_type' => FaceEnrollment::class, 'result_id' => $enrollment->id, 'received_at' => now()]);

            return $enrollment;
        });

        return response()->json($enrollment, 201);
    }

    private function assertTrustedSyncNode(string $nodeId): void
    {
        $allowed = config('offline.allowed_node_ids', []);
        $allowed = is_array($allowed) ? $allowed : [];

        abort_unless(
            in_array($nodeId, $allowed, true),
            403,
            'This store node is not authorized to synchronize account changes.'
        );
    }
}
