<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_pos_sale_deducts_stock_and_creates_ledger(): void
    {
        $cashier = User::where('role', 'cashier')->first();
        $product = Product::first();
        $before = $product->stock_quantity;
        $this->actingAs($cashier)->postJson('/api/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('items.0.quantity', 2);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => $before - 2]);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'type' => 'sale', 'quantity_delta' => -2]);
    }

    public function test_online_order_reserves_then_receipt_deducts_stock(): void
    {
        $customer = User::factory()->create(['role' => 'user', 'is_active' => true]);
        $product = Product::first();
        $before = $product->stock_quantity;
        $response = $this->actingAs($customer)->postJson('/api/orders', ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payment_method' => 'protected', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $order = Order::find($response->json('id'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => $before, 'reserved_quantity' => 3]);
        $admin = User::where('role', 'admin')->first();
        $this->actingAs($admin)->putJson("/api/orders/{$order->id}/status", ['status' => 'dispatched'])->assertOk();
        $this->putJson("/api/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();
        $this->actingAs($customer)->postJson("/api/orders/{$order->id}/receive")->assertOk()->assertJsonPath('payment_status', 'paid');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => $before - 3, 'reserved_quantity' => 0]);
    }

    public function test_overselling_is_rejected(): void
    {
        $cashier = User::where('role', 'cashier')->first();
        $product = Product::first();
        $this->actingAs($cashier)->postJson('/api/pos/checkout', ['items' => [['product_id' => $product->id, 'quantity' => 999999]], 'payment_method' => 'cash', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_retrying_a_sale_does_not_deduct_stock_twice(): void
    {
        $cashier = User::where('role', 'cashier')->first();
        $product = Product::first();
        $before = $product->stock_quantity;
        $key = (string) Str::uuid();
        $payload = ['items' => [['product_id' => $product->id, 'quantity' => 2]], 'payment_method' => 'cash', 'idempotency_key' => $key];

        $first = $this->actingAs($cashier)->postJson('/api/pos/checkout', $payload)->assertCreated();
        $second = $this->postJson('/api/pos/checkout', $payload)->assertCreated();

        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock_quantity' => $before - 2]);
        $this->assertDatabaseCount('sales', 1);
    }

    public function test_new_product_opening_stock_is_audited(): void
    {
        $admin = User::where('role', 'admin')->first();
        $response = $this->actingAs($admin)->postJson('/api/products', [
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'barcode' => '999000000001',
            'category' => 'Tools',
            'unit' => 'pcs',
            'price' => 100,
            'stock_quantity' => 12,
        ])->assertCreated()->assertJsonPath('stock_quantity', 12);

        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $response->json('id'),
            'type' => 'opening_stock',
            'quantity_delta' => 12,
        ]);
    }
}
