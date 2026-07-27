<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Product;
use App\Models\SyncConflict;
use App\Models\SyncOutbox;
use App\Models\User;
use App\Services\LocalSyncService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OfflineSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_local_pos_sale_is_written_to_the_outbox(): void
    {
        config(['offline.enabled' => true]);
        $cashier = User::where('role', 'cashier')->first();
        $product = Product::first();
        $eventId = (string) Str::uuid();

        $this->actingAs($cashier)->postJson('/api/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'payment_method' => 'cash',
            'idempotency_key' => $eventId,
        ])->assertCreated();

        $this->assertDatabaseHas('sync_outbox', ['event_id' => $eventId, 'event_type' => 'sale.completed', 'status' => 'pending']);
    }

    public function test_cloud_import_is_idempotent_and_deducts_inventory_once(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $product = Product::first();
        $before = $product->stock_quantity;
        $eventId = (string) Str::uuid();
        $payload = $this->salePayload($product, 2);

        $first = $this->withToken('test-sync-secret')->postJson('/api/sync/sales', ['node_id' => 'store-main', 'event_id' => $eventId, 'payload' => $payload])->assertCreated();
        $second = $this->withToken('test-sync-secret')->postJson('/api/sync/sales', ['node_id' => 'store-main', 'event_id' => $eventId, 'payload' => $payload])->assertOk();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => $before - 2]);
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('sync_receipts', 1);
    }

    public function test_cloud_rejects_an_offline_sale_that_conflicts_with_available_stock(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $product = Product::first();

        $this->withToken('test-sync-secret')->postJson('/api/sync/sales', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $this->salePayload($product, $product->stock_quantity + 1),
        ])->assertUnprocessable();

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_cloud_rebases_a_versioned_inventory_delta_without_overwriting_newer_stock(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $base = Product::firstOrFail()->fresh();
        $eventId = (string) Str::uuid();
        $payload = $this->productPayload($base, [
            'stock_quantity' => $base->stock_quantity + 2,
            'version' => $base->version + 1,
            'sync' => [
                'base_version' => $base->version,
                'base_updated_at' => $base->updated_at->toIso8601String(),
                'stock_delta' => 2,
                'reserved_delta' => 0,
                'metadata_changed' => false,
                'captured_at' => now()->toIso8601String(),
            ],
        ]);

        $base->update([
            'stock_quantity' => $base->stock_quantity + 5,
            'version' => $base->version + 1,
        ]);

        $this->withToken('test-sync-secret')->postJson('/api/sync/products', [
            'node_id' => 'store-main',
            'event_id' => $eventId,
            'payload' => $payload,
        ])->assertCreated();
        $this->withToken('test-sync-secret')->postJson('/api/sync/products', [
            'node_id' => 'store-main',
            'event_id' => $eventId,
            'payload' => $payload,
        ])->assertOk();

        $this->assertSame((int) $payload['stock_quantity'] + 5, $base->fresh()->stock_quantity);
        $this->assertDatabaseCount('sync_receipts', 1);
    }

    public function test_cloud_rejects_stale_product_metadata_and_legacy_inventory_snapshots(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $base = Product::firstOrFail()->fresh();
        $baseVersion = $base->version;
        $baseStock = $base->stock_quantity;
        $base->update([
            'name' => 'Cloud authoritative name',
            'stock_quantity' => $baseStock + 3,
            'version' => $baseVersion + 1,
        ]);

        $versioned = $this->productPayload($base, [
            'name' => 'Stale local name',
            'stock_quantity' => $baseStock,
            'version' => $baseVersion + 1,
            'sync' => [
                'base_version' => $baseVersion,
                'base_updated_at' => now()->subMinute()->toIso8601String(),
                'stock_delta' => 0,
                'reserved_delta' => 0,
                'metadata_changed' => true,
                'captured_at' => now()->toIso8601String(),
            ],
        ]);
        $this->withToken('test-sync-secret')->postJson('/api/sync/products', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $versioned,
        ])->assertConflict()->assertJsonPath('code', 'stale_product_version');

        $legacy = $this->productPayload($base, [
            'stock_quantity' => $baseStock,
            'version' => $baseVersion,
        ]);
        $this->withToken('test-sync-secret')->postJson('/api/sync/products', [
            'node_id' => 'legacy-store',
            'event_id' => (string) Str::uuid(),
            'payload' => $legacy,
        ])->assertConflict()->assertJsonPath('code', 'unsafe_legacy_inventory_snapshot');

        $this->assertSame('Cloud authoritative name', $base->fresh()->name);
        $this->assertSame($baseStock + 3, $base->fresh()->stock_quantity);
        $this->assertDatabaseCount('sync_receipts', 0);
    }

    public function test_multiple_local_adjustments_are_coalesced_as_one_inventory_delta(): void
    {
        config(['offline.enabled' => true]);
        $admin = User::where('role', 'admin')->firstOrFail();
        $product = Product::firstOrFail()->fresh();
        $baseVersion = $product->version;

        $this->actingAs($admin)->postJson("/api/products/{$product->id}/adjust", [
            'quantity_delta' => 5,
            'reason' => 'Delivery received',
        ])->assertOk();
        $this->actingAs($admin)->postJson("/api/products/{$product->id}/adjust", [
            'quantity_delta' => -2,
            'reason' => 'Count correction',
        ])->assertOk();

        $event = SyncOutbox::where('event_type', 'product.updated')
            ->where('aggregate_id', $product->id)
            ->sole();
        $this->assertSame($baseVersion, data_get($event->payload, 'sync.base_version'));
        $this->assertSame(3, data_get($event->payload, 'sync.stock_delta'));
        $this->assertFalse(data_get($event->payload, 'sync.metadata_changed'));
    }

    public function test_local_worker_pushes_outbox_before_refreshing_cloud_inventory(): void
    {
        config([
            'offline.enabled' => true,
            'offline.node_id' => 'store-main',
            'offline.cloud_url' => 'https://cloud.example',
            'offline.sync_token' => 'sync-secret',
        ]);
        $cashier = User::where('role', 'cashier')->first();
        $product = Product::first();
        $eventId = (string) Str::uuid();
        $this->actingAs($cashier)->postJson('/api/pos/checkout', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'cash',
            'idempotency_key' => $eventId,
        ])->assertCreated();

        Http::fake([
            'https://cloud.example/api/sync/sales' => Http::response(['id' => 99], 201),
            'https://cloud.example/api/sync/products' => Http::response(Product::all()->toArray()),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([[
                'id' => 901, 'product_id' => $product->id, 'type' => 'sale',
                'quantity_delta' => -1, 'reserved_delta' => 0,
                'stock_before' => 180, 'stock_after' => 179,
                'reserved_before' => 0, 'reserved_after' => 0,
                'reason' => 'Cloud sale', 'idempotency_key' => (string) Str::uuid(),
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
            ]]),
            'https://cloud.example/api/sync/configuration' => Http::response(['users' => [], 'employees' => [], 'devices' => []]),
            'https://cloud.example/api/sync/orders' => Http::response([]),
            'https://cloud.example/api/sync/attendance' => Http::response([]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['online']);
        $this->assertSame(1, $result['synced_now']);
        $this->assertDatabaseHas('sync_outbox', ['event_id' => $eventId, 'status' => 'synced']);
        $this->assertDatabaseHas('sync_states', ['key' => 'cloud']);
        $this->assertDatabaseHas('sync_states', ['key' => 'cloud_inventory_activity']);
        Http::assertSentCount(7);
    }

    public function test_open_conflict_does_not_block_safe_cloud_pulls(): void
    {
        config([
            'offline.enabled' => true,
            'offline.node_id' => 'store-main',
            'offline.cloud_url' => 'https://cloud.example',
            'offline.sync_token' => 'sync-secret',
        ]);
        $event = SyncOutbox::create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'product.updated',
            'aggregate_type' => Product::class,
            'aggregate_id' => Product::firstOrFail()->id,
            'payload' => $this->productPayload(Product::firstOrFail()),
            'status' => 'conflict',
        ]);
        SyncConflict::create([
            'outbox_id' => $event->id,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'reason' => 'A newer cloud edit exists.',
            'local_payload' => $event->payload,
            'remote_response' => ['code' => 'stale_product_version'],
            'status' => 'open',
        ]);
        Http::fake([
            'https://cloud.example/api/sync/products' => Http::response(Product::all()->toArray()),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([]),
            'https://cloud.example/api/sync/configuration' => Http::response(['users' => [], 'employees' => [], 'devices' => []]),
            'https://cloud.example/api/sync/orders' => Http::response([]),
            'https://cloud.example/api/sync/attendance' => Http::response([]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['online']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertTrue($result['activity_synced']);
        Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example/api/sync/products');
    }

    public function test_admin_can_rebase_or_discard_a_sync_conflict(): void
    {
        config(['offline.enabled' => true]);
        $admin = User::where('role', 'admin')->firstOrFail();
        $product = Product::firstOrFail();
        $cloudStock = $product->stock_quantity;
        $cloudReserved = $product->reserved_quantity;
        $cloudVersion = $product->version;
        $cloudUpdatedAt = $product->updated_at->toIso8601String();
        $product->update([
            'stock_quantity' => $cloudStock + 4,
            'version' => $cloudVersion + 1,
        ]);
        $payload = $this->productPayload($product, [
            'sync' => [
                'base_version' => $cloudVersion,
                'base_updated_at' => $cloudUpdatedAt,
                'stock_delta' => 4,
                'reserved_delta' => 0,
                'metadata_changed' => false,
                'captured_at' => now()->toIso8601String(),
            ],
        ]);
        $event = SyncOutbox::create([
            'event_id' => (string) Str::uuid(),
            'event_type' => 'product.updated',
            'aggregate_type' => Product::class,
            'aggregate_id' => $product->id,
            'payload' => $payload,
            'status' => 'conflict',
        ]);
        $conflict = SyncConflict::create([
            'outbox_id' => $event->id,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'reason' => 'Version mismatch',
            'local_payload' => $payload,
            'remote_response' => [
                'code' => 'stale_product_version',
                'remote' => [
                    'sku' => $product->sku,
                    'stock_quantity' => $cloudStock,
                    'reserved_quantity' => $cloudReserved,
                    'version' => $cloudVersion,
                    'updated_at' => $cloudUpdatedAt,
                ],
            ],
            'status' => 'open',
        ]);

        $this->actingAs($admin)->postJson("/api/local-sync/conflicts/{$conflict->id}/resolve", [
            'action' => 'retry',
        ])->assertOk()->assertJsonPath('status', 'retrying');

        $event->refresh();
        $this->assertSame('pending', $event->status);
        $this->assertSame($cloudVersion, data_get($event->payload, 'sync.base_version'));
        $this->assertSame($cloudStock + 4, $event->payload['stock_quantity']);
        $this->assertSame($product->stock_quantity, $event->payload['stock_quantity'], 'Retry must not add the delta twice to local stock.');

        $this->actingAs($admin)->postJson("/api/local-sync/conflicts/{$conflict->id}/resolve", [
            'action' => 'accept_remote',
        ])->assertOk()->assertJsonPath('status', 'resolved');
        $this->assertDatabaseHas('sync_outbox', ['id' => $event->id, 'status' => 'discarded']);
    }

    public function test_local_worker_imports_cloud_orders_for_fulfillment(): void
    {
        config([
            'offline.enabled' => true,
            'offline.node_id' => 'store-main',
            'offline.cloud_url' => 'https://cloud.example',
            'offline.sync_token' => 'sync-secret',
        ]);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $product = Product::first();
        $orderKey = (string) Str::uuid();
        Http::fake([
            'https://cloud.example/api/sync/products' => Http::response(Product::all()->toArray()),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([]),
            'https://cloud.example/api/sync/configuration' => Http::response(['users' => [], 'employees' => [], 'devices' => []]),
            'https://cloud.example/api/sync/orders' => Http::response([[
                'reference' => 'WEB-CLOUD-001', 'idempotency_key' => $orderKey,
                'customer_email' => $customer->email, 'status' => 'preparing', 'payment_status' => 'on_hold',
                'payment_method' => 'protected', 'payment_reference' => null,
                'subtotal' => 285, 'discount_total' => 0, 'vat_rate' => 0.12,
                'vatable_sales' => 254.46, 'vat_amount' => 30.54, 'total' => 285,
                'dispatched_at' => null, 'delivered_at' => null, 'received_at' => null, 'cancelled_at' => null,
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                'items' => [[
                    'product_name' => $product->name, 'sku' => $product->sku, 'quantity' => 1,
                    'unit_price' => 285, 'discount_percent' => 0, 'line_total' => 285,
                    'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(),
                ]],
            ]]),
            'https://cloud.example/api/sync/attendance' => Http::response([]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['orders_synced']);
        $this->assertDatabaseHas('orders', ['idempotency_key' => $orderKey, 'reference' => 'WEB-CLOUD-001']);
        $this->assertDatabaseHas('order_items', ['sku' => $product->sku, 'quantity' => 1]);
    }

    public function test_local_worker_imports_cloud_attendance(): void
    {
        config([
            'offline.enabled' => true,
            'offline.node_id' => 'store-main',
            'offline.cloud_url' => 'https://cloud.example',
            'offline.sync_token' => 'sync-secret',
        ]);
        $employee = Employee::first();
        $recognizedAt = now()->setMicrosecond(0);
        Http::fake([
            'https://cloud.example/api/sync/products' => Http::response(Product::all()->toArray()),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([]),
            'https://cloud.example/api/sync/configuration' => Http::response(['users' => [], 'employees' => [], 'devices' => []]),
            'https://cloud.example/api/sync/orders' => Http::response([]),
            'https://cloud.example/api/sync/attendance' => Http::response([[
                'employee_number' => $employee->employee_number,
                'device_external_id' => null, 'device_name' => null, 'device_type' => null,
                'attendance_date' => $recognizedAt->format('Y-m-d'), 'status' => 'present',
                'recognized_at' => $recognizedAt->toIso8601String(), 'match_confidence' => 96.5,
                'provider_event_id' => 'cloud-face-001', 'metadata' => ['source' => 'mobile'],
                'created_at' => $recognizedAt->toIso8601String(), 'updated_at' => $recognizedAt->toIso8601String(),
            ]]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['attendance_synced']);
        $record = AttendanceRecord::where('provider_event_id', 'cloud-face-001')->firstOrFail();
        $this->assertSame($employee->id, $record->employee_id);
        $this->assertSame($recognizedAt->format('Y-m-d'), $record->attendance_date->format('Y-m-d'));
        $this->assertSame('present', $record->status);
    }

    public function test_local_order_and_fulfillment_changes_are_queued_for_cloud(): void
    {
        config(['offline.enabled' => true]);
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $product = Product::first();
        $response = $this->actingAs($customer)->postJson('/api/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'payment_method' => 'protected', 'idempotency_key' => (string) Str::uuid(),
        ])->assertCreated();
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->putJson('/api/orders/'.$response->json('id').'/status', ['status' => 'dispatched'])->assertOk();

        $this->assertDatabaseHas('sync_outbox', ['event_type' => 'order.placed', 'status' => 'pending']);
        $this->assertDatabaseHas('sync_outbox', ['event_type' => 'order.status_updated', 'status' => 'pending']);
    }

    public function test_local_finalized_payroll_is_queued_for_cloud(): void
    {
        config(['offline.enabled' => true]);
        $admin = User::where('role', 'admin')->firstOrFail();
        $this->actingAs($admin)->postJson('/api/payroll/runs', [
            'period_start' => '2026-07-01', 'period_end' => '2026-07-07',
        ])->assertCreated();

        $this->assertDatabaseHas('sync_outbox', ['event_type' => 'payroll.finalized', 'status' => 'pending']);
    }

    public function test_local_worker_imports_cloud_payroll_snapshots(): void
    {
        config(['offline.enabled' => true, 'offline.node_id' => 'store-main', 'offline.cloud_url' => 'https://cloud.example', 'offline.sync_token' => 'sync-secret']);
        $admin = User::where('role', 'admin')->firstOrFail();
        $employee = Employee::firstOrFail();
        $item = ['employee_number' => $employee->employee_number, 'base_pay' => 5000, 'incentive' => 250, 'overtime_pay' => 100, 'gross_pay' => 5350, 'sss' => 250, 'pagibig' => 100, 'philhealth' => 125, 'net_pay' => 4875, 'calculation' => ['gross_pay' => 5350]];
        Http::fake([
            'https://cloud.example/api/sync/products' => Http::response(Product::all()->toArray()),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([]),
            'https://cloud.example/api/sync/configuration' => Http::response(['users' => [], 'employees' => [], 'devices' => []]),
            'https://cloud.example/api/sync/orders' => Http::response([]),
            'https://cloud.example/api/sync/attendance' => Http::response([]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([[
                'reference' => 'PAY-CLOUD-001', 'period_start' => '2026-07-01', 'period_end' => '2026-07-07',
                'status' => 'finalized', 'created_by_email' => $admin->email, 'finalized_at' => now()->toIso8601String(),
                'created_at' => now()->toIso8601String(), 'updated_at' => now()->toIso8601String(), 'items' => [$item],
            ]]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['payroll_synced']);
        $this->assertDatabaseHas('payroll_runs', ['reference' => 'PAY-CLOUD-001', 'status' => 'finalized']);
        $this->assertDatabaseHas('payroll_items', ['employee_id' => $employee->id, 'net_pay' => 4875]);
    }

    public function test_cloud_payroll_import_is_idempotent_but_rejects_a_changed_snapshot(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $admin = User::where('role', 'admin')->firstOrFail();
        $employee = Employee::firstOrFail();
        $payload = $this->payrollPayload($admin, $employee);

        $first = $this->withToken('test-sync-secret')->postJson('/api/sync/payroll-runs', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $payload,
        ])->assertCreated();

        $retry = $this->withToken('test-sync-secret')->postJson('/api/sync/payroll-runs', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $payload,
        ])->assertOk();

        $this->assertSame($first->json('id'), $retry->json('id'));
        $this->assertDatabaseCount('payroll_runs', 1);
        $this->assertDatabaseCount('payroll_items', 1);
        $this->assertDatabaseCount('sync_receipts', 2);

        $changed = $payload;
        $changed['items'][0]['net_pay'] = 4874;
        $this->withToken('test-sync-secret')->postJson('/api/sync/payroll-runs', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $changed,
        ])->assertStatus(409)
            ->assertJsonPath(
                'message',
                'A different finalized payroll snapshot already exists for this reference or period.'
            );

        $this->assertDatabaseHas('payroll_items', [
            'employee_id' => $employee->id,
            'net_pay' => 4875,
        ]);
        $this->assertDatabaseCount('sync_receipts', 2);
    }

    public function test_payroll_feed_preserves_creator_identity_after_the_account_is_erased(): void
    {
        config(['offline.sync_token' => 'test-sync-secret']);
        $creator = User::where('role', 'assistant')->firstOrFail();
        $originalEmail = $creator->email;
        $originalName = $creator->name;

        $this->actingAs($creator)->postJson('/api/payroll/runs', [
            'period_start' => '2026-07-08',
            'period_end' => '2026-07-14',
        ])->assertCreated();

        $anonymizedEmail = 'deleted-creator@anonymized.invalid';
        $creator->forceFill([
            'name' => 'Deleted account',
            'email' => $anonymizedEmail,
            'is_active' => false,
        ])->save();
        $creator->delete();

        $this->withToken('test-sync-secret')
            ->getJson('/api/sync/payroll-runs')
            ->assertOk()
            ->assertJsonPath('0.created_by_email', $originalEmail)
            ->assertJsonPath('0.created_by_name', $originalName)
            ->assertJsonPath('0.created_by_account_email', $anonymizedEmail);
    }

    public function test_payroll_import_conflict_does_not_roll_back_other_cloud_data(): void
    {
        config([
            'offline.enabled' => true,
            'offline.node_id' => 'store-main',
            'offline.cloud_url' => 'https://cloud.example',
            'offline.sync_token' => 'sync-secret',
        ]);
        $admin = User::where('role', 'admin')->firstOrFail();
        $employee = Employee::firstOrFail();
        $payload = $this->payrollPayload($admin, $employee);

        $this->withToken('sync-secret')->postJson('/api/sync/payroll-runs', [
            'node_id' => 'store-main',
            'event_id' => (string) Str::uuid(),
            'payload' => $payload,
        ])->assertCreated();

        $remoteProducts = Product::all()->map(function (Product $product, int $index) {
            $payload = $this->productPayload($product);
            if ($index === 0) {
                $payload['name'] = 'Cloud product refresh survived';
            }

            return $payload;
        })->values()->all();
        $changedPayroll = $payload;
        $changedPayroll['items'][0]['net_pay'] = 4800;

        Http::fake([
            'https://cloud.example/api/sync/products' => Http::response($remoteProducts),
            'https://cloud.example/api/sync/inventory-activity' => Http::response([]),
            'https://cloud.example/api/sync/configuration' => Http::response([
                'users' => [],
                'employees' => [],
                'devices' => [],
            ]),
            'https://cloud.example/api/sync/orders' => Http::response([]),
            'https://cloud.example/api/sync/attendance' => Http::response([]),
            'https://cloud.example/api/sync/payroll-runs' => Http::response([$changedPayroll]),
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['online']);
        $this->assertFalse($result['payroll_synced']);
        $this->assertStringContainsString('payroll snapshot needs review', $result['message']);
        $this->assertSame('Cloud product refresh survived', Product::orderBy('id')->first()->name);
        $this->assertDatabaseHas('payroll_items', ['net_pay' => 4875]);
        $this->assertDatabaseHas('sync_states', ['key' => 'cloud_payroll_conflict']);
    }

    private function salePayload(Product $product, int $quantity): array
    {
        $subtotal = (float) $product->price * $quantity;

        return [
            'reference' => 'POS-LOCAL-001',
            'cashier_email' => 'cashier@nenial.com',
            'payment_method' => 'cash',
            'subtotal' => $subtotal,
            'discount_total' => 0,
            'total' => $subtotal,
            'completed_at' => now()->toIso8601String(),
            'items' => [[
                'sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => (float) $product->price,
                'discount_percent' => 0,
                'line_total' => $subtotal,
            ]],
        ];
    }

    private function productPayload(Product $product, array $overrides = []): array
    {
        return [
            ...$product->only([
                'name', 'sku', 'barcode', 'category', 'supplier', 'unit', 'price',
                'discount_percent', 'stock_quantity', 'reserved_quantity',
                'safety_stock', 'reorder_level', 'version', 'image_url', 'is_active',
            ]),
            'deleted_at' => $product->deleted_at?->toIso8601String(),
            'updated_at' => $product->updated_at?->toIso8601String(),
            ...$overrides,
        ];
    }

    private function payrollPayload(User $admin, Employee $employee): array
    {
        return [
            'reference' => 'PAY-LOCAL-IMMUTABLE-001',
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-07',
            'status' => 'finalized',
            'created_by_email' => $admin->email,
            'finalized_at' => '2026-07-08T00:00:00+08:00',
            'items' => [[
                'employee_number' => $employee->employee_number,
                'employee_name' => $employee->name,
                'job_title' => $employee->job_title,
                'base_pay' => 5000,
                'incentive' => 250,
                'overtime_pay' => 100,
                'gross_pay' => 5350,
                'sss' => 250,
                'pagibig' => 100,
                'philhealth' => 125,
                'net_pay' => 4875,
                'calculation' => ['gross_pay' => 5350, 'source' => 'finalized'],
            ]],
        ];
    }
}
