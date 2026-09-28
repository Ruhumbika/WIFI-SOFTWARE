<?php
namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SnippeSessionService
{
    public function start(Order $order, SnippeClient $client): Payment
    {
        abort_if($order->paid_at || $order->status !== 'pending_payment', 409, 'This order is no longer awaiting payment.');
        $existing = $order->payments()->latest('id')->first();
        if ($existing) {
            abort_unless($existing->provider === 'snippe' && $existing->session_reference && $existing->checkout_url && $existing->status === 'pending', 409, 'An existing payment must be resolved before another can be started.');
            $gateway = $existing->gatewayAccount;
            abort_unless($gateway && $gateway->business_id === $order->business_id && $existing->business_id === $order->business_id, 409, 'Payment account mismatch.');
            $client->assertConfigured($gateway);
            $session = data_get($client->getSession($gateway, $existing->session_reference), 'data');
            abort_unless(is_array($session) && ($session['reference'] ?? null) === $existing->session_reference
                && ($session['amount'] ?? null) === (int) $order->amount && ($session['currency'] ?? null) === $order->currency, 502, 'Could not verify checkout.');
            abort_unless(in_array($session['status'] ?? '', ['pending','active'], true), 409, 'Checkout is no longer pending. Wait for payment confirmation or contact support.');
            $client->checkoutUrl($existing->checkout_url);
            return $existing;
        }
        $gateways = $order->business?->gatewayAccounts()->where('provider', 'snippe')->where('active', true)->get();
        abort_unless($gateways && $gateways->count() === 1, 503, 'Payment is unavailable right now.');
        $gateway = $gateways->first();
        $client->assertConfigured($gateway);
        abort_unless($gateway->payment_profile_id, 503, 'Payment is unavailable right now.');
        $client->assertUrl((string) config('snippe.portal_url'));
        $client->assertUrl($gateway->webhook_url);
        // Persist intent before network I/O. An ambiguous timeout must never create a second session.
        $payment = DB::transaction(function () use ($order, $gateway) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);
            abort_if($locked->paid_at || $locked->payments()->exists(), 409, 'A payment request already exists.');
            return Payment::create(['uuid'=>(string) Str::uuid(), 'order_id'=>$order->id, 'business_id'=>$order->business_id,
                'payment_gateway_account_id'=>$gateway->id, 'provider'=>'snippe', 'status'=>'pending',
                'amount'=>$order->amount, 'currency'=>$order->currency, 'idempotency_key'=>Str::random(30)]);
        });
        $data = data_get($client->createSession($order, $payment, $gateway), 'data');
        abort_unless(is_array($data) && is_string($data['reference'] ?? null) && str_starts_with($data['reference'], 'sess_')
            && ($data['amount'] ?? null) === (int) $order->amount && ($data['currency'] ?? null) === $order->currency
            && in_array($data['status'] ?? '', ['pending','active'], true)
            && ($data['collect_email'] ?? true) === false
            && ($data['allowed_methods'] ?? []) === ['mobile_money'], 502, 'Checkout configuration could not be verified.');
        $url = $client->checkoutUrl($data['checkout_url'] ?? '');
        $payment->forceFill(['session_reference'=>$data['reference'], 'checkout_url'=>$url,
            'provider_payload'=>['session_reference'=>$data['reference'], 'status'=>$data['status']]])->save();
        return $payment;
    }
}
