<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\PaymentCheckoutService;
use Illuminate\Http\Request;
use Stripe\Webhook;
use Throwable;

class PaymentController extends Controller
{
    public function checkout(Request $request, Order $order, PaymentCheckoutService $payments)
    {
        abort_unless($request->user()->role === 'user' && $order->customer_id === $request->user()->id, 403);
        $data = $request->validate(['provider' => 'required|in:stripe,gcash,maya']);
        $order->loadMissing('items', 'customer');

        return $payments->create($order, $data['provider']);
    }

    public function stripe(Request $request)
    {
        $secret = config('services.stripe.webhook_secret');
        abort_unless($secret, 503);
        try {
            $event = Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (Throwable) {
            abort(400, 'Invalid Stripe signature.');
        }
        if ($event->type === 'checkout.session.completed' && $event->data->object->payment_status === 'paid') {
            $this->markPaid('stripe', $event->data->object->id, (string) ($event->data->object->payment_intent ?? $event->data->object->id), ['event_id' => $event->id]);
        }
        return response()->noContent();
    }

    public function payMongo(Request $request)
    {
        $secret = config('services.paymongo.webhook_secret');
        abort_unless($secret && $this->validPayMongoSignature($request, $secret), 400, 'Invalid PayMongo signature.');
        $payload = $request->json()->all();
        if (data_get($payload, 'data.attributes.type') === 'checkout_session.payment.paid') {
            $session = data_get($payload, 'data.attributes.data');
            $this->markPaid('gcash', data_get($session, 'id'), data_get($session, 'attributes.payments.0.id', data_get($payload, 'data.id')), ['event_id' => data_get($payload, 'data.id')]);
        }
        return response()->noContent();
    }

    public function maya(Request $request)
    {
        abort_unless(config('services.maya.webhook_secret') && hash_equals((string) config('services.maya.webhook_secret'), (string) $request->query('token')), 403);
        if (in_array(strtoupper((string) $request->input('paymentStatus')), ['PAYMENT_SUCCESS', 'SUCCESS'], true)) {
            $order = Order::where('payment_provider', 'maya')->where('reference', $request->input('requestReferenceNumber'))->first();
            if ($order) $this->markPaid('maya', $order->provider_session_id, (string) $request->input('id', $order->provider_session_id), ['receipt' => $request->input('receiptNumber')]);
        }
        return response()->noContent();
    }

    private function markPaid(string $provider, ?string $sessionId, string $reference, array $metadata): void
    {
        if (! $sessionId) return;
        Order::where('payment_provider', $provider)->where('provider_session_id', $sessionId)
            ->where('payment_status', 'on_hold')->update([
                'payment_status' => 'paid', 'payment_reference' => $reference,
                'paid_at' => now(), 'payment_metadata' => json_encode($metadata), 'updated_at' => now(),
            ]);
    }

    private function validPayMongoSignature(Request $request, string $secret): bool
    {
        $parts = collect(explode(',', (string) $request->header('Paymongo-Signature')))->mapWithKeys(function ($part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            return [$key => $value];
        });
        $signature = str_starts_with((string) config('services.paymongo.secret'), 'sk_live_') ? $parts->get('li') : $parts->get('te');
        $timestamp = (int) $parts->get('t');
        return $timestamp && abs(now()->timestamp - $timestamp) <= 300 && $signature
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret), $signature);
    }
}
