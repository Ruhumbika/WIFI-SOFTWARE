<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResendPaymentPushTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPayment(): array
    {
        config()->set('snippe.allowed_api_hosts', ['snippe.test']);
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'HOUR',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_HOUR', 'active' => true,
        ]);
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'order_number' => 'ORD-PUSH-1',
            'plan_id' => $plan->id, 'customer_phone' => '255700000001',
            'amount' => 500, 'currency' => 'TZS', 'status' => 'pending_payment',
        ]);
        $gateway = \App\Models\PaymentGatewayAccount::create(['uuid'=>(string) Str::uuid(), 'business_id'=>$order->business_id, 'provider'=>'snippe', 'api_key_encrypted'=>'test-only', 'webhook_secret_encrypted'=>'test-signing', 'base_url'=>'https://snippe.test', 'webhook_url'=>'https://portal.test/webhook', 'active'=>true]);
        $payment = Payment::create(['payment_gateway_account_id'=>$gateway->id,
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'snippe',
            'reference' => 'payment-reference', 'status' => 'pending', 'amount' => 500,
            'currency' => 'TZS', 'idempotency_key' => 'test-push-1',
        ]);
        return [$order, $payment, ['X-Order-Token' => Crypt::encryptString($order->uuid)]];
    }

    public function test_resend_requires_a_wait_and_order_token(): void
    {
        [$order, $payment, $headers] = $this->pendingPayment();
        Cache::put('payment-push-at:'.$payment->id, now()->timestamp, now()->addHour());
        Http::fake();

        $this->postJson("/api/public/orders/{$order->uuid}/resend-push")->assertForbidden();
        $this->postJson("/api/public/orders/{$order->uuid}/resend-push", [], $headers)->assertStatus(429);
        Http::assertNothingSent();
    }

    public function test_resend_checks_provider_and_reuses_the_same_payment(): void
    {
        [$order, $payment, $headers] = $this->pendingPayment();
        Cache::put('payment-push-at:'.$payment->id, now()->subMinutes(6)->timestamp, now()->addHour());
        Http::fake([
            'https://snippe.test/v1/payments/payment-reference/push' => Http::response(['data' => ['status' => 'pending']], 200),
            'https://snippe.test/v1/payments/payment-reference' => Http::response(['data' => ['status' => 'pending']], 200),
        ]);

        $this->postJson("/api/public/orders/{$order->uuid}/resend-push", [], $headers)
            ->assertOk()->assertJsonPath('payment.reference', 'payment-reference');
        $this->assertSame(1, $order->payments()->count());
        Http::assertSentCount(2);
        $this->postJson("/api/public/orders/{$order->uuid}/resend-push", [], $headers)->assertStatus(429);
        Http::assertSentCount(2);
    }

    public function test_completed_provider_payment_cannot_receive_another_push(): void
    {
        [$order, $payment, $headers] = $this->pendingPayment();
        Cache::put('payment-push-at:'.$payment->id, now()->subMinutes(6)->timestamp, now()->addHour());
        Http::fake(['https://snippe.test/v1/payments/payment-reference' => Http::response(['data' => ['status' => 'completed']], 200)]);

        $this->postJson("/api/public/orders/{$order->uuid}/resend-push", [], $headers)->assertStatus(409);
        Http::assertSentCount(1);
    }
}
