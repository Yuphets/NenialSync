<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class PaymentCheckoutService
{
    public function create(Order $order, string $provider): Order
    {
        if ($order->payment_url && $order->payment_provider === $provider && $order->payment_expires_at?->isFuture()) {
            return $order;
        }

        abort_unless($order->status === 'preparing' && $order->payment_status === 'on_hold', 422, 'This order can no longer start a payment.');

        if ($provider !== 'paymongo') {
            throw ValidationException::withMessages(['provider' => 'PayMongo is the only supported online payment provider.']);
        }

        return $this->payMongo($order);
    }

    private function payMongo(Order $order): Order
    {
        $secret = config('services.paymongo.secret');
        abort_unless($secret, 503, 'PayMongo checkout is not configured.');
        $response = Http::acceptJson()->withBasicAuth($secret, '')->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => ['attributes' => [
                'description' => "Nenial order {$order->reference}",
                'reference_number' => $order->reference,
                'send_email_receipt' => true,
                'show_line_items' => true,
                'success_url' => url('/app/orders?payment=success'),
                'cancel_url' => url('/app/orders?payment=cancelled'),
                'payment_method_types' => config('services.paymongo.payment_methods'),
                'line_items' => $order->items->map(fn ($item) => [
                    'currency' => 'PHP', 'quantity' => $item->quantity,
                    'amount' => (int) round(((float) $item->line_total / $item->quantity) * 100),
                    'name' => $item->product_name, 'description' => $item->sku,
                ])->values()->all(),
            ]],
        ])->throw()->json('data');

        return $this->save($order, 'paymongo', $response['id'], $response['attributes']['checkout_url'], now()->addHours(1));
    }

    private function save(Order $order, string $provider, string $id, string $url, $expires): Order
    {
        $order->update([
            'payment_provider' => $provider, 'provider_session_id' => $id,
            'payment_url' => $url, 'payment_expires_at' => $expires,
        ]);

        return $order->fresh(['items', 'customer']);
    }
}
