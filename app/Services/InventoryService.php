<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function adjust(Product $product, int $quantityDelta, int $reservedDelta, string $type, ?User $actor, ?string $reason = null, mixed $reference = null): Product
    {
        return DB::transaction(function () use ($product, $quantityDelta, $reservedDelta, $type, $actor, $reason, $reference) {
            $locked = Product::query()->lockForUpdate()->findOrFail($product->id);
            $stockAfter = $locked->stock_quantity + $quantityDelta;
            $reservedAfter = $locked->reserved_quantity + $reservedDelta;

            if ($stockAfter < 0 || $reservedAfter < 0 || $reservedAfter > $stockAfter) {
                throw ValidationException::withMessages(['inventory' => "Invalid inventory movement for {$locked->name}."]);
            }

            $before = $locked->only(['stock_quantity', 'reserved_quantity', 'version']);
            $locked->update(['stock_quantity' => $stockAfter, 'reserved_quantity' => $reservedAfter, 'version' => $locked->version + 1]);
            InventoryMovement::create([
                'product_id' => $locked->id, 'actor_id' => $actor?->id, 'type' => $type,
                'quantity_delta' => $quantityDelta, 'reserved_delta' => $reservedDelta,
                'stock_before' => $before['stock_quantity'], 'stock_after' => $stockAfter,
                'reserved_before' => $before['reserved_quantity'], 'reserved_after' => $reservedAfter,
                'reference_type' => $reference ? $reference::class : null, 'reference_id' => $reference?->id,
                'reason' => $reason, 'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->audit($actor, 'inventory.adjusted', $locked, $before, $locked->fresh()->toArray(), compact('type', 'reason'));

            return $locked->fresh();
        });
    }

    public function completeSale(User $cashier, array $lines, string $paymentMethod, float $saleDiscount, string $idempotencyKey): Sale
    {
        if ($existing = Sale::with('items', 'cashier')->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($cashier, $lines, $paymentMethod, $saleDiscount, $idempotencyKey) {
            $products = $this->lockProducts($lines);
            [$items, $subtotal, $discount] = $this->priceLines($products, $lines);
            $discount += round(max(0, min(100, $saleDiscount)) / 100 * ($subtotal - $discount), 2);
            $sale = Sale::create([
                'reference' => 'POS-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'idempotency_key' => $idempotencyKey,
                'cashier_id' => $cashier->id, 'channel' => 'pos', 'payment_method' => $paymentMethod,
                'status' => 'completed', 'subtotal' => $subtotal, 'discount_total' => $discount,
                'total' => $subtotal - $discount, 'completed_at' => now(),
            ]);

            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                if ($product->available_quantity < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "Only {$product->available_quantity} {$product->unit} of {$product->name} are available."]);
                }
                $sale->items()->create($item);
                $this->moveLocked($product, -$item['quantity'], 0, 'sale', $cashier, 'POS sale', $sale);
            }
            $this->audit($cashier, 'sale.completed', $sale, null, $sale->load('items')->toArray());

            return $sale->load('items', 'cashier');
        }, 3);
    }

    public function placeOrder(User $customer, array $lines, string $paymentMethod, string $idempotencyKey): Order
    {
        if ($existing = Order::with('items', 'customer')->where('idempotency_key', $idempotencyKey)->first()) {
            return $existing;
        }

        return DB::transaction(function () use ($customer, $lines, $paymentMethod, $idempotencyKey) {
            $products = $this->lockProducts($lines);
            [$items, $subtotal, $discount] = $this->priceLines($products, $lines);
            $order = Order::create([
                'reference' => 'WEB-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
                'idempotency_key' => $idempotencyKey,
                'customer_id' => $customer->id, 'status' => 'preparing', 'payment_status' => 'on_hold',
                'payment_method' => $paymentMethod, 'subtotal' => $subtotal, 'discount_total' => $discount,
                'total' => $subtotal - $discount,
            ]);
            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                if ($product->available_quantity < $item['quantity']) {
                    throw ValidationException::withMessages(['items' => "Only {$product->available_quantity} {$product->unit} of {$product->name} are available."]);
                }
                $order->items()->create($item);
                $this->moveLocked($product, 0, $item['quantity'], 'reservation', $customer, 'Online order reservation', $order);
            }
            $this->audit($customer, 'order.placed', $order, null, $order->load('items')->toArray());

            return $order->load('items', 'customer');
        }, 3);
    }

    public function receiveOrder(Order $order, User $customer): Order
    {
        return DB::transaction(function () use ($order, $customer) {
            $lockedOrder = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->customer_id !== $customer->id || $lockedOrder->status !== 'delivered') {
                throw ValidationException::withMessages(['order' => 'Only a delivered order may be confirmed by its customer.']);
            }
            foreach ($lockedOrder->items->sortBy('product_id') as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $this->moveLocked($product, -$item->quantity, -$item->quantity, 'order_fulfilled', $customer, 'Customer confirmed receipt', $lockedOrder);
            }
            $lockedOrder->update(['status' => 'received', 'payment_status' => 'paid', 'received_at' => now()]);
            $this->audit($customer, 'order.received', $lockedOrder, null, $lockedOrder->fresh()->toArray());

            return $lockedOrder->fresh(['items', 'customer']);
        }, 3);
    }

    public function cancelOrder(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $lockedOrder = Order::query()->with('items')->lockForUpdate()->findOrFail($order->id);
            if (! in_array($lockedOrder->status, ['preparing', 'dispatched'], true)) {
                throw ValidationException::withMessages(['order' => 'This order can no longer be cancelled.']);
            }
            foreach ($lockedOrder->items->sortBy('product_id') as $item) {
                $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                $this->moveLocked($product, 0, -$item->quantity, 'reservation_released', $actor, 'Order cancelled', $lockedOrder);
            }
            $lockedOrder->update(['status' => 'cancelled', 'payment_status' => 'voided', 'cancelled_at' => now()]);
            $this->audit($actor, 'order.cancelled', $lockedOrder, null, $lockedOrder->fresh()->toArray());

            return $lockedOrder->fresh(['items', 'customer']);
        }, 3);
    }

    private function lockProducts(array $lines)
    {
        $ids = collect($lines)->pluck('product_id')->map(fn ($id) => (int) $id)->unique()->sort()->values();
        $products = Product::query()->whereIn('id', $ids)->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($products->count() !== $ids->count()) {
            throw ValidationException::withMessages(['items' => 'One or more products are unavailable.']);
        }

        return $products;
    }

    private function priceLines($products, array $lines): array
    {
        $subtotal = 0;
        $discount = 0;
        $items = [];
        foreach ($lines as $line) {
            $product = $products[(int) $line['product_id']];
            $quantity = max(1, (int) $line['quantity']);
            $raw = round((float) $product->price * $quantity, 2);
            $lineDiscount = round($raw * (float) $product->discount_percent / 100, 2);
            $subtotal += $raw;
            $discount += $lineDiscount;
            $items[] = ['product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'quantity' => $quantity, 'unit_price' => $product->price, 'discount_percent' => $product->discount_percent, 'line_total' => $raw - $lineDiscount];
        }

        return [$items, round($subtotal, 2), round($discount, 2)];
    }

    private function moveLocked(Product $product, int $stockDelta, int $reservedDelta, string $type, ?User $actor, string $reason, mixed $reference): void
    {
        $stockBefore = $product->stock_quantity;
        $reservedBefore = $product->reserved_quantity;
        $stockAfter = $stockBefore + $stockDelta;
        $reservedAfter = $reservedBefore + $reservedDelta;
        if ($stockAfter < 0 || $reservedAfter < 0 || $reservedAfter > $stockAfter) {
            throw ValidationException::withMessages(['inventory' => "Concurrent stock change prevented this transaction for {$product->name}."]);
        }
        $product->update(['stock_quantity' => $stockAfter, 'reserved_quantity' => $reservedAfter, 'version' => $product->version + 1]);
        InventoryMovement::create(['product_id' => $product->id, 'actor_id' => $actor?->id, 'type' => $type, 'quantity_delta' => $stockDelta, 'reserved_delta' => $reservedDelta, 'stock_before' => $stockBefore, 'stock_after' => $stockAfter, 'reserved_before' => $reservedBefore, 'reserved_after' => $reservedAfter, 'reference_type' => $reference::class, 'reference_id' => $reference->id, 'reason' => $reason, 'idempotency_key' => (string) Str::uuid()]);
        $product->refresh();
    }

    private function audit(?User $actor, string $action, mixed $model, ?array $before, ?array $after, array $metadata = []): void
    {
        AuditLog::create(['actor_id' => $actor?->id, 'action' => $action, 'auditable_type' => $model::class, 'auditable_id' => $model->id, 'before' => $before, 'after' => $after, 'metadata' => $metadata, 'ip_address' => request()?->ip()]);
    }
}
