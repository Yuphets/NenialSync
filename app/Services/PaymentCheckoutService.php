<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Stripe\StripeClient;

class PaymentCheckoutService
{
    public function create(Order $order, string $provider): Order
    {
        if ($order->payment_url && $order->payment_provider === $provider && $order->payment_expires_at?->isFuture()) {
            return $order;
        }

        abort_unless($order->status === 'preparing' && $order->payment_status === 'on_hold', 422, 'This order can no longer start a payment.');

        return match ($provider) {
            'stripe' => $this->stripe($order),
            'gcash' => $this->payMongo($order),
            'maya' => $this->maya($order),
            default => throw ValidationException::withMessages(['provider' => 'Choose Stripe card, GCash, or Maya.']),
        };
    }

    private function stripe(Order $order): Order
    {
        $secret = config('services.stripe.secret');
        abort_unless($secret, 503, 'Stripe checkout is not configured.');
        $session = (new StripeClient($secret))->checkout->sessions->create([
            'mode' => 'payment',
            'customer_email' => $order->customer->email,
            'client_reference_id' => $order->reference,
            'success_url' => url('/app/orders?payment=success&session_id={CHECKOUT_SESSION_ID}'),
            'cancel_url' => url('/app/orders?payment=cancelled'),
            'line_items' => $order->items->map(fn ($item) => [
                'quantity' => $item->quantity,
                'price_data' => [
                    'currency' => 'php',
                    'unit_amount' => (int) round(((float) $item->line_total / $item->quantity) * 100),
                    'product_data' => ['name' => $item->product_name, 'metadata' => ['sku' => $item->sku]],
                ],
            ])->values()->all(),
            'metadata' => ['order_id' => (string) $order->id, 'order_reference' => $order->reference],
        ], ['idempotency_key' => 'nenial-'.$order->idempotency_key]);

        return $this->save($order, 'stripe', $session->id, $session->url, now()->addHours(24));
    }

    private function payMongo(Order $order): Order
    {
        $secret = config('services.paymongo.secret');
        abort_unless($secret, 503, 'GCash checkout is not configured.');
        $response = Http::acceptJson()->withBasicAuth($secret, '')->post('https://api.paymongo.com/v1/checkout_sessions', [
            'data' => ['attributes' => [
                'description' => "Nenial order {$order->reference}",
                'reference_number' => $order->reference,
                'send_email_receipt' => true,
                'show_line_items' => true,
                'success_url' => url('/app/orders?payment=success'),
                'cancel_url' => url('/app/orders?payment=cancelled'),
                'payment_method_types' => ['gcash'],
                'line_items' => $order->items->map(fn ($item) => [
                    'currency' => 'PHP', 'quantity' => $item->quantity,
                    'amount' => (int) round(((float) $item->line_total / $item->quantity) * 100),
                    'name' => $item->product_name, 'description' => $item->sku,
                ])->values()->all(),
            ]],
        ])->throw()->json('data');

        return $this->save($order, 'gcash', $response['id'], $response['attributes']['checkout_url'], now()->addHours(1));
    }

    private function maya(Order $order): Order
    {
        $key = config('services.maya.public_key');
        abort_unless($key, 503, 'Maya checkout is not configured.');
        $response = Http::acceptJson()->withBasicAuth($key, '')->post(rtrim(config('services.maya.base_url'), '/').'/checkout/v1/checkouts', [
            'totalAmount' => ['value' => (float) $order->total, 'currency' => 'PHP'],
            'buyer' => ['firstName' => $order->customer->name, 'contact' => ['email' => $order->customer->email]],
            'items' => $order->items->map(fn ($item) => [
                'name' => $item->product_name, 'code' => $item->sku, 'quantity' => $item->quantity,
                'amount' => ['value' => round((float) $item->line_total / $item->quantity, 2)],
                'totalAmount' => ['value' => (float) $item->line_total],
            ])->values()->all(),
            'requestReferenceNumber' => $order->reference,
            'redirectUrl' => [
                'success' => url('/app/orders?payment=success'),
                'failure' => url('/app/orders?payment=failed'),
                'cancel' => url('/app/orders?payment=cancelled'),
            ],
        ])->throw()->json();

        return $this->save($order, 'maya', $response['checkoutId'], $response['redirectUrl'], now()->addHours(1));
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
