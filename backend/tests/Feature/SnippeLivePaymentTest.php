<?php

namespace Tests\Feature;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SnippeLivePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'HOUR',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_HOUR', 'active' => true,
        ]);

        return Order::create([
            'uuid' => (string) Str::uuid(), 'order_number' => 'ORD-TEST-LIVE',
            'plan_id' => $plan->id, 'customer_phone' => '255700000001',
            'customer_name' => 'Test Customer', 'customer_email' => 'customer@example.test',
            'amount' => 500, 'currency' => 'TZS', 'status' => 'pending_payment',
        ]);
    }

    public function test_payment_requires_real_configuration_and_has_no_simulation_route(): void
    {
        $order = $this->order();
        config()->set('clickpesa.api_key', null);
        $headers = ['X-Order-Token' => Crypt::encryptString($order->uuid)];

        $this->postJson('/api/public/orders/'.$order->uuid.'/pay', [], $headers)->assertStatus(502);
        $this->postJson('/api/public/orders/'.$order->uuid.'/mock-complete', [], $headers)->assertNotFound();
        $this->assertNull($order->fresh()->paid_at);
    }

    public function test_signed_matching_webhook_completes_real_payment_only_once(): void
    {
        $order = $this->order();
        config()->set('snippe.webhook_secret', 'test-signing-key');
        Bus::fake([ProvisionPaidOrder::class]);
        $payment = Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'snippe',
            'reference' => 'pi_test_reference', 'status' => 'pending', 'amount' => 500,
            'currency' => 'TZS', 'idempotency_key' => 'test-legacy-snippe',
        ]);
        $this->assertSame('pending', $payment->status);

        $event = [
            'id' => 'evt_test_payment', 'type' => 'payment.completed',
            'data' => [
                'reference' => 'pi_test_reference', 'status' => 'completed',
                'amount' => ['value' => 501, 'currency' => 'TZS'],
                'metadata' => ['order_uuid' => $order->uuid],
            ],
        ];
        $this->call('POST', '/api/webhooks/snippe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) time(),
            'HTTP_X_WEBHOOK_SIGNATURE' => 'invalid',
        ], json_encode($event, JSON_THROW_ON_ERROR))->assertStatus(400);
        $this->sendWebhook($event)->assertStatus(422);
        $this->assertNull($order->fresh()->paid_at);

        $event['data']['amount']['value'] = 500;
        $this->sendWebhook($event)->assertOk();
        $this->sendWebhook($event)->assertOk()->assertJsonPath('duplicate', true);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertNotNull($order->fresh()->paid_at);
        Bus::assertDispatchedTimes(ProvisionPaidOrder::class, 1);
    }

    private function sendWebhook(array $event): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'test-signing-key');

        return $this->call('POST', '/api/webhooks/snippe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
        ], $body);
    }
}
