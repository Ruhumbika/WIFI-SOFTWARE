<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentConsumer;
use App\Models\PaymentEvent;
use App\Models\PaymentGatewayAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ConsumerPaymentService
{
    public function gateway(PaymentConsumer $consumer): PaymentGatewayAccount
    {
        $gateways = $consumer->business->gatewayAccounts()->where('provider', 'snippe')->where('active', true)->get();
        abort_unless($gateways->count() === 1 && $gateways->first()->payment_profile_id, 503, 'Payment configuration unavailable.');
        $gateway = $gateways->first();
        app(SnippeClient::class)->assertConfigured($gateway);
        return $gateway;
    }

    public function start(PaymentConsumer $consumer, array $input): Payment
    {
        $client = app(SnippeClient::class);
        $fingerprint = hash_hmac('sha256', json_encode([
            $input['source_reference'], (int) $input['attempt'], (int) $input['amount'], $input['currency'], $input['phone'] ?? null, $input['kind'], $input['return_path'],
        ], JSON_THROW_ON_ERROR), config('app.key'));
        [$payment, $created] = DB::transaction(function () use ($consumer, $input, $fingerprint, $client) {
            // Serialize attempts per installation, including retries before the provider responds.
            PaymentConsumer::lockForUpdate()->findOrFail($consumer->id);
            $query = Payment::where('payment_consumer_id', $consumer->id)->where('source_reference', $input['source_reference']);
            $existing = (clone $query)->where('source_attempt', $input['attempt'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'Payment retry details changed.');
                return [$existing, false];
            }
            $previous = $query->orderByDesc('source_attempt')->first();
            abort_if($previous && ($previous->status === 'completed' || !in_array($previous->status, ['failed', 'expired', 'voided'], true)), 409, 'Resolve the existing payment first.');
            abort_unless((int) $input['attempt'] === ($previous ? $previous->source_attempt + 1 : 1), 409, 'Invalid payment attempt.');
            if ($previous && $previous->status === 'failed') {
                // A failed phone attempt does not close its hosted session. Close it before another charge.
                abort_unless($previous->session_reference, 409, 'Reconcile the previous session first.');
                $session = data_get($client->getSession($previous->gatewayAccount, $previous->session_reference), 'data');
                $this->assertSession($previous, $consumer, $session);
                abort_if(in_array($session['status'] ?? '', ['active', 'completed'], true), 409, 'Previous payment is awaiting confirmation.');
                if (($session['status'] ?? '') === 'pending') {
                    $client->cancelSession($previous->gatewayAccount, $previous->session_reference);
                    $session = data_get($client->getSession($previous->gatewayAccount, $previous->session_reference), 'data');
                    $this->assertSession($previous, $consumer, $session);
                }
                abort_unless(in_array($session['status'] ?? '', ['expired', 'cancelled'], true), 409, 'Previous session must be closed before retrying.');
                $previous->forceFill(['status'=>'voided'])->save();
            }
            $gateway = $this->gateway($consumer);
            return [Payment::create([
                'uuid'=>(string) Str::uuid(), 'business_id'=>$consumer->business_id,
                'payment_consumer_id'=>$consumer->id, 'payment_gateway_account_id'=>$gateway->id,
                'source_return_path'=>$input['return_path'], 'source_kind'=>$input['kind'], 'source_reference'=>$input['source_reference'], 'source_attempt'=>(int) $input['attempt'],
                'request_fingerprint'=>$fingerprint, 'customer_phone_encrypted'=>$input['phone'] ?? null,
                'provider'=>'snippe', 'status'=>'pending', 'amount'=>(int) $input['amount'], 'currency'=>$input['currency'],
                'idempotency_key'=>Str::random(30), 'provider_payload'=>['creation_state'=>'submitting'],
            ]), true];
        });
        if (!$created) return $payment;

        try {
            $data = data_get($client->createConsumerSession($payment, $consumer, $payment->gatewayAccount), 'data');
            abort_unless(is_array($data) && is_string($data['reference'] ?? null) && strlen($data['reference']) <= 255 && trim($data['reference']) !== ''
                && ($data['amount'] ?? null) === (int) $payment->amount && ($data['currency'] ?? null) === $payment->currency
                && in_array($data['status'] ?? '', ['pending', 'active'], true)
                && ($data['collect_email'] ?? true) === false && ($data['allow_custom_amount'] ?? true) === false
                && ($data['allowed_methods'] ?? []) === ['mobile_money'], 502, 'Provider configuration mismatch.');
            $url = $client->checkoutUrl($data['checkout_url'] ?? '');
            $submit = DB::transaction(function () use ($payment, $data, $url) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);
                $locked->forceFill(['session_reference'=>$data['reference'], 'checkout_url'=>$url])->save();
                if ($locked->status !== 'pending') return false;
                $locked->forceFill(['provider_payload'=>array_merge($locked->provider_payload ?? [], [
                    'creation_state'=>'created', 'checkout_submission'=>['state'=>'submitting'],
                ])])->save();
                return true;
            });
            if ($submit && $input['kind'] === 'push') {
                $attempt = $client->submitCheckout($url, $input['phone']);
                // Do not store provider tokens or complete response bodies.
                $safe = array_intersect_key($attempt, array_flip(['state', 'attempt_id', 'expires_at']));
                $safe['prompt_sent'] = ($attempt['state'] ?? '') === 'initiated' && !empty($attempt['payment_token']);
                DB::transaction(function () use ($payment, $safe) {
                    $locked = Payment::lockForUpdate()->findOrFail($payment->id);
                    if ($locked->status !== 'pending') return;
                    $locked->forceFill(['provider_payload'=>array_merge($locked->provider_payload ?? [], ['checkout_submission'=>$safe])])->save();
                });
            }
        } catch (\Throwable $e) {
            // Unknown outcomes remain pending. A retry returns the same intent and cannot send a second push.
            DB::transaction(function () use ($payment) {
                $locked = Payment::lockForUpdate()->findOrFail($payment->id);
                if ($locked->status !== 'pending') return;
                $locked->forceFill(['provider_payload'=>array_merge($locked->provider_payload ?? [], ['creation_state'=>'unknown'])])->save();
            });
        }
        return $payment->fresh();
    }

    private function assertSession(Payment $payment, PaymentConsumer $consumer, mixed $data): void
    {
        abort_unless(is_array($data) && ($data['reference'] ?? null) === $payment->session_reference
            && ($data['amount'] ?? null) === (int) $payment->amount && ($data['currency'] ?? null) === $payment->currency
            && data_get($data, 'metadata.payment_uuid') === $payment->uuid
            && data_get($data, 'metadata.business_uuid') === $consumer->business->uuid
            && data_get($data, 'metadata.system') === $consumer->code
            && data_get($data, 'metadata.source_reference') === $payment->source_reference, 502, 'Could not verify previous payment session.');
    }

    public function view(Payment $payment, PaymentConsumer $consumer): array
    {
        abort_unless($payment->business_id === $consumer->business_id && $payment->payment_consumer_id === $consumer->id, 403);
        $submission = $payment->provider_payload['checkout_submission'] ?? [];
        return [
            'payment_id'=>$payment->uuid, 'business_code'=>$consumer->business->code,
            'source_reference'=>$payment->source_reference, 'attempt'=>$payment->source_attempt,
            'amount'=>(int) $payment->amount, 'currency'=>$payment->currency, 'status'=>$payment->status,
            'verified'=>$payment->status === 'completed' && $payment->completed_at !== null,
            'provider_reference'=>$payment->reference,
            'kind'=>$payment->source_kind,
            'checkout_url'=>$payment->source_kind === 'checkout' ? $payment->checkout_url : null,
            'payment_prompt_sent'=>($submission['prompt_sent'] ?? false) === true,
            'initiation_state'=>$submission['state'] ?? ($payment->provider_payload['creation_state'] ?? 'unknown'),
        ];
    }

    /** Handle external payments only. WiFi order fulfillment continues in its existing controller. */
    public function webhook(PaymentGatewayAccount $gateway, array $event, string $eventId): array
    {
        return DB::transaction(function () use ($gateway, $event, $eventId) {
            $uuid = data_get($event, 'data.metadata.payment_uuid');
            abort_unless(is_string($uuid) && Str::isUuid($uuid), 422, 'Missing payment metadata.');
            $payment = Payment::where('uuid', $uuid)->where('payment_gateway_account_id', $gateway->id)
                ->whereNotNull('payment_consumer_id')->lockForUpdate()->firstOrFail();
            $consumer = PaymentConsumer::with('business')->findOrFail($payment->payment_consumer_id);
            abort_unless($payment->order_id === null && $payment->business_id === $gateway->business_id
                && $consumer->business_id === $gateway->business_id
                && is_int(data_get($event, 'data.amount.value'))
                && data_get($event, 'data.amount.value') === (int) $payment->amount
                && data_get($event, 'data.amount.currency') === $payment->currency, 422, 'Payment details mismatch.');
            foreach (['business_uuid'=>$consumer->business->uuid, 'payment_uuid'=>$payment->uuid,
                'system'=>$consumer->code, 'source_reference'=>$payment->source_reference] as $key=>$expected) {
                abort_unless(data_get($event, 'data.metadata.'.$key) === $expected, 422, 'Payment metadata mismatch.');
            }
            $reference = data_get($event, 'data.reference');
            $session = data_get($event, 'data.session_reference');
            abort_if($session !== null && $payment->session_reference !== null && $session !== $payment->session_reference, 422, 'Session mismatch.');
            if (PaymentEvent::where('event_id', $eventId)->exists()) return ['duplicate'=>true];
            $type = $event['type'];
            if ($type === 'payment.completed') {
                abort_unless(data_get($event, 'data.status') === 'completed', 422, 'Payment status mismatch.');
                abort_if($payment->status === 'completed' && $payment->reference !== $reference, 422, 'Payment reference mismatch.');
                $settlement = app(PaymentSettlementService::class)->validate($payment, data_get($event, 'data.settlement'));
                $payment->forceFill(['reference'=>$reference, 'status'=>'completed', 'completed_at'=>$payment->completed_at ?? now()] + $settlement)->save();
            } elseif ($payment->status !== 'completed') {
                // The consumer submitted one phone attempt; failures are retained for explicit retries.
                $payment->forceFill(['reference'=>$reference, 'status'=>substr($type, 8)])->save();
            }
            PaymentEvent::create(['payment_id'=>$payment->id, 'event_id'=>$eventId, 'event_type'=>$type,
                'reference'=>$reference, 'payload'=>['type'=>$type, 'reference'=>$reference], 'processed_at'=>now()]);
            return ['duplicate'=>false];
        });
    }
}
