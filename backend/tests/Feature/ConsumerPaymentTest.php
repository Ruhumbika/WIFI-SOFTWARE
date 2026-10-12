<?php

namespace Tests\Feature;

use App\Jobs\ProvisionPaidOrder;
use App\Models\{Business, PaymentConsumer, PaymentGatewayAccount, Payment, PaymentEvent};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Bus, DB, Http};
use Illuminate\Support\Str;
use Tests\TestCase;

class ConsumerPaymentTest extends TestCase
{
    use RefreshDatabase;

    private PaymentConsumer $consumer;
    private PaymentGatewayAccount $gateway;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Bus::fake([ProvisionPaidOrder::class]);
        $business = Business::create(['uuid'=>(string) Str::uuid(), 'code'=>'RJAY_BARBERSHOP', 'name'=>'Barbershop', 'status'=>'active']);
        $this->token = Str::random(48);
        $this->consumer = PaymentConsumer::create(['uuid'=>(string) Str::uuid(), 'business_id'=>$business->id,
            'code'=>'barbershop', 'token_hash'=>hash('sha256', $this->token), 'return_origin'=>'https://rjaybarber.shop', 'active'=>true]);
        $this->gateway = PaymentGatewayAccount::create(['uuid'=>(string) Str::uuid(), 'business_id'=>$business->id,
            'provider'=>'snippe', 'base_url'=>'https://api.snippe.sh', 'api_key_encrypted'=>'merchant-test-key',
            'webhook_secret_encrypted'=>'merchant-test-secret', 'payment_profile_id'=>'prof_barber',
            'webhook_url'=>'https://central.test/api/webhooks/snippe/test', 'active'=>true]);
    }

    private function headers(): array
    {
        return ['Authorization'=>'Bearer '.$this->token, 'X-Business-Code'=>'RJAY_BARBERSHOP'];
    }

    private function input(): array
    {
        return ['source_reference'=>'booking-123', 'attempt'=>1, 'kind'=>'push', 'return_path'=>'/booking-success?booking_id=booking-123', 'amount'=>15000, 'currency'=>'TZS', 'phone'=>'255712345678'];
    }

    private function fakeProvider(bool $push = true): void
    {
        Http::fake(['https://api.snippe.sh/api/v1/sessions'=>Http::response(['data'=>[
            'reference'=>'sess_barber', 'status'=>'pending', 'amount'=>15000, 'currency'=>'TZS',
            'checkout_url'=>'https://snippe.me/checkout/barberToken', 'collect_email'=>false,
            'allow_custom_amount'=>false, 'allowed_methods'=>['mobile_money'],
        ]], 201), 'https://api.snippe.sh/checkout/barberToken/pay'=>Http::response(['data'=>[
            'attempt_id'=>'attempt_1', 'status'=>'pending', 'payment_token'=>$push ? 'private-token' : null,
        ]])]);
    }

    private function start(): Payment
    {
        $this->fakeProvider();
        $this->postJson('/api/integrations/payments', $this->input(), $this->headers())->assertOk()->assertJsonPath('payment_prompt_sent', true);
        return Payment::where('payment_consumer_id', $this->consumer->id)->firstOrFail();
    }

    private function event(Payment $payment, string $type = 'payment.completed'): array
    {
        return ['id'=>'evt_'.$type, 'type'=>$type, 'data'=>['reference'=>'pi_barber', 'session_reference'=>'sess_barber',
            'status'=>substr($type, 8), 'amount'=>['value'=>15000, 'currency'=>'TZS'], 'metadata'=>[
                'business_uuid'=>$this->consumer->business->uuid, 'payment_uuid'=>$payment->uuid,
                'system'=>'barbershop', 'source_reference'=>'booking-123',
            ]]];
    }

    private function webhook(array $event, ?PaymentGatewayAccount $gateway = null, ?string $signature = null)
    {
        $gateway ??= $this->gateway;
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        return $this->call('POST', '/api/webhooks/snippe/'.$gateway->uuid, [], [], [], [
            'CONTENT_TYPE'=>'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP'=>$timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE'=>$signature ?? hash_hmac('sha256', $timestamp.'.'.$body, $gateway->webhook_secret_encrypted),
        ], $body);
    }

    public function test_profile_credentials_are_resolved_server_side_and_push_is_not_repeated(): void
    {
        $payment = $this->start();
        $retry = $this->postJson('/api/integrations/payments', $this->input(), $this->headers())->assertOk();
        $retry->assertJsonPath('payment_id', $payment->uuid)->assertJsonMissingPath('api_key')->assertJsonMissingPath('payment_profile_id');
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.snippe.sh/api/v1/sessions'
            && $r->hasHeader('Authorization', 'Bearer merchant-test-key') && $r['profile_id'] === 'prof_barber'
            && $r['customer']['phone'] === '255712345678' && $r['metadata']['system'] === 'barbershop');
        Http::assertSent(fn ($r) => $r->url() === 'https://api.snippe.sh/checkout/barberToken/pay'
            && !$r->hasHeader('Authorization') && $r['customer_phone'] === '255712345678');
        $this->assertNull($payment->order_id);
        $this->assertStringNotContainsString('255712345678', DB::table('payments')->where('id', $payment->id)->value('customer_phone_encrypted'));
        $this->assertStringNotContainsString('private-token', json_encode($payment->fresh()->provider_payload));
        Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }

    public function test_wrong_identity_frontend_merchant_selection_and_changed_retries_are_rejected(): void
    {
        $this->postJson('/api/integrations/payments', $this->input())->assertUnauthorized();
        $this->postJson('/api/integrations/payments', $this->input(), array_merge($this->headers(), ['X-Business-Code'=>'RJAY_WIFI']))->assertForbidden();
        $this->postJson('/api/integrations/payments', $this->input() + ['payment_profile_id'=>'prof_wifi'], $this->headers())->assertUnprocessable();
        $this->start();
        $this->postJson('/api/integrations/payments', array_merge($this->input(), ['amount'=>16000]), $this->headers())->assertConflict();
        $other = Str::random(48);
        PaymentConsumer::create(['uuid'=>(string) Str::uuid(), 'business_id'=>$this->consumer->business_id, 'code'=>'other',
            'token_hash'=>hash('sha256', $other), 'return_origin'=>'https://other.test', 'active'=>true]);
        $payment = Payment::firstOrFail();
        $this->getJson('/api/integrations/payments/'.$payment->uuid, array_merge($this->headers(), ['Authorization'=>'Bearer '.$other]))->assertNotFound();
    }

    public function test_unknown_creation_cannot_create_a_second_provider_session(): void
    {
        Http::fake(['*'=>Http::response(['message'=>'unavailable'], 503)]);
        $first = $this->postJson('/api/integrations/payments', $this->input(), $this->headers())->assertOk()->assertJsonPath('initiation_state', 'unknown');
        $this->postJson('/api/integrations/payments', $this->input(), $this->headers())->assertOk()->assertJsonPath('payment_id', $first->json('payment_id'));
        Http::assertSentCount(1);
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_a_reference_without_a_provider_push_token_is_not_reported_as_pin_sent(): void
    {
        $this->fakeProvider(false);
        $this->postJson('/api/integrations/payments', $this->input(), $this->headers())->assertOk()->assertJsonPath('payment_prompt_sent', false);
    }

    public function test_signed_webhook_completes_once_and_never_provisions_wifi(): void
    {
        $payment = $this->start();
        $event = $this->event($payment);
        $this->webhook($event)->assertOk();
        $this->webhook($event)->assertOk()->assertJsonPath('duplicate', true);
        $this->webhook($this->event($payment, 'payment.failed'))->assertOk();
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertDatabaseCount('payment_events', 2);
        $this->getJson('/api/integrations/payments/'.$payment->uuid, $this->headers())->assertOk()->assertJsonPath('verified', true);
        $this->assertDatabaseCount('orders', 0);
        Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }

    public function test_invalid_signature_amount_currency_source_and_gateway_cannot_confirm(): void
    {
        $payment = $this->start();
        $event = $this->event($payment);
        $this->webhook($event, signature:'invalid')->assertStatus(400);
        foreach (['amount', 'currency', 'source', 'system'] as $field) {
            $bad = $event;
            if ($field === 'amount') $bad['data']['amount']['value'] = 14999;
            if ($field === 'currency') $bad['data']['amount']['currency'] = 'USD';
            if ($field === 'source') $bad['data']['metadata']['source_reference'] = 'booking-other';
            if ($field === 'system') $bad['data']['metadata']['system'] = 'wifi';
            $this->webhook($bad)->assertUnprocessable();
        }
        $wifi = Business::where('code', 'RJAY_WIFI')->firstOrFail();
        $wrong = $this->gateway->replicate();
        $wrong->uuid = (string) Str::uuid(); $wrong->business_id = $wifi->id; $wrong->save();
        $this->webhook($event, $wrong)->assertNotFound();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_walkin_qr_uses_same_business_profile_without_automatic_push(): void
    {
        $this->fakeProvider();
        $input = array_merge($this->input(), ['kind'=>'checkout', 'phone'=>null, 'source_reference'=>'walkin-123']);
        $this->postJson('/api/integrations/payments', $input, $this->headers())->assertOk()
            ->assertJsonPath('checkout_url', 'https://snippe.me/checkout/barberToken')->assertJsonPath('payment_prompt_sent', false);
        Http::assertSentCount(1);
        Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }
    public function test_retry_closes_failed_session_before_creating_another_charge(): void
    {
        $payment = $this->start();
        $this->webhook($this->event($payment, 'payment.failed'))->assertOk();
        $session = ['reference'=>'sess_barber', 'amount'=>15000, 'currency'=>'TZS',
            'metadata'=>$this->event($payment)['data']['metadata']];
        Http::fake([
            'https://api.snippe.sh/api/v1/sessions/sess_barber'=>Http::sequence()
                ->push(['data'=>$session + ['status'=>'pending']])
                ->push(['data'=>$session + ['status'=>'cancelled']]),
            'https://api.snippe.sh/api/v1/sessions/sess_barber/cancel'=>Http::response(['code'=>200]),
            'https://api.snippe.sh/api/v1/sessions'=>Http::response(['data'=>[
                'reference'=>'sess_retry', 'status'=>'pending', 'amount'=>15000, 'currency'=>'TZS',
                'checkout_url'=>'https://snippe.me/checkout/barberRetry', 'collect_email'=>false,
                'allow_custom_amount'=>false, 'allowed_methods'=>['mobile_money'],
            ]], 201),
            'https://api.snippe.sh/checkout/barberRetry/pay'=>Http::response(['data'=>[
                'attempt_id'=>'attempt_retry', 'status'=>'pending', 'payment_token'=>'private-token',
            ]]),
        ]);
        $this->postJson('/api/integrations/payments', array_merge($this->input(), ['attempt'=>2]), $this->headers())->assertOk();
        $this->assertSame('voided', $payment->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sess_barber/cancel'));
    }

    public function test_active_previous_payment_blocks_a_new_attempt(): void
    {
        $payment = $this->start();
        $this->webhook($this->event($payment, 'payment.failed'))->assertOk();
        Http::fake(['https://api.snippe.sh/api/v1/sessions/sess_barber'=>Http::response(['data'=>[
            'reference'=>'sess_barber', 'amount'=>15000, 'currency'=>'TZS', 'status'=>'active',
            'metadata'=>$this->event($payment)['data']['metadata'],
        ]])]);
        $this->postJson('/api/integrations/payments', array_merge($this->input(), ['attempt'=>2]), $this->headers())->assertConflict();
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }

    public function test_unsafe_return_paths_and_email_collecting_profile_do_not_trigger_push(): void
    {
        $this->postJson('/api/integrations/payments', array_merge($this->input(), ['return_path'=>'//untrusted.test']), $this->headers())->assertUnprocessable();
        Http::fake(['*'=>Http::response(['data'=>[
            'reference'=>'sess_barber', 'status'=>'pending', 'amount'=>15000, 'currency'=>'TZS',
            'checkout_url'=>'https://snippe.me/checkout/barberToken', 'collect_email'=>true,
            'allow_custom_amount'=>false, 'allowed_methods'=>['mobile_money'],
        ]])]);
        $this->postJson('/api/integrations/payments', $this->input(), $this->headers())->assertOk()->assertJsonPath('payment_prompt_sent', false);
        Http::assertSentCount(1);
    }

}
