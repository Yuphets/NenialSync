<?php

namespace Tests\Feature;

use App\Models\Product;
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
        ]);

        $result = app(LocalSyncService::class)->run();

        $this->assertTrue($result['online']);
        $this->assertSame(1, $result['synced_now']);
        $this->assertDatabaseHas('sync_outbox', ['event_id' => $eventId, 'status' => 'synced']);
        $this->assertDatabaseHas('sync_states', ['key' => 'cloud']);
        Http::assertSentCount(2);
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
}
