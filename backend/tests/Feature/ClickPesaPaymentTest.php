<?php

namespace Tests\Feature;

use App\Jobs\ProvisionPaidOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ClickPesaPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function setupProvider(): void
    {
        config()->set('clickpesa.client_id', 'test-client');
        config()->set('clickpesa.api_key', 'test-key');
        config()->set('clickpesa.checksum_key', 'test-checksum-key');
        Cache::forget('clickpesa:authorization-token');
        Bus::fake([ProvisionPaidOrder::class]);
    }

    private function plan(): Plan
    {
        return Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'HOUR',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_HOUR', 'active' => true,
        ]);
    }

    public function test_phone_only_order_and_provider_verified_payment(): void
    {
        $this->setupProvider();
        $order = $this->postJson('/api/public/orders', [
            'plan_id' => $this->plan()->id, 'phone' => '0700000001',
        ])->assertCreated()->json();
        $this->assertNull(Order::where('uuid', $order['uuid'])->firstOrFail()->customer_name);
        $this->assertNull(Order::where('uuid', $order['uuid'])->firstOrFail()->customer_email);

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/generate-token')) return Http::response(['success' => true, 'token' => 'Bearer test-token']);
            if (str_ends_with($request->url(), '/initiate-ussd-push-request')) return Http::response([
                'id' => 'cp-1', 'status' => 'PROCESSING', 'orderReference' => $request['orderReference'],
            ]);
            if (str_contains($request->url(), '/payments/')) return Http::response([[
                'id' => 'cp-1', 'status' => 'SUCCESS', 'orderReference' => basename($request->url()),
                'clientId' => 'test-client', 'collectedAmount' => 500, 'collectedCurrency' => 'TZS',
                'paymentPhoneNumber' => '255700000001', 'paymentReference' => 'receipt-1',
            ]]);
            return Http::response([], 404);
        });
        $headers = ['X-Order-Token' => Crypt::encryptString($order['uuid'])];
        $this->postJson('/api/public/orders/'.$order['uuid'].'/pay', [], $headers)->assertOk();
        $payment = Payment::where('order_id', Order::where('uuid', $order['uuid'])->firstOrFail()->id)->firstOrFail();
        $this->assertSame('clickpesa', $payment->provider);
        $this->assertSame(20, strlen($payment->reference));
        Http::assertSent(fn($request) => str_ends_with($request->url(), '/initiate-ussd-push-request')
            && $request['phoneNumber'] === '255700000001'
            && !isset($request['customer']) && !isset($request['email']));

        $event = ['event' => 'PAYMENT RECEIVED', 'data' => ['orderReference' => $payment->reference]];
        $this->postJson('/api/webhooks/clickpesa', $event)->assertStatus(400);
        $this->assertNull(Order::where('uuid', $order['uuid'])->firstOrFail()->paid_at);
        $event['checksum'] = $this->checksum($event);
        $this->postJson('/api/webhooks/clickpesa', $event)->assertOk();
        $this->postJson('/api/webhooks/clickpesa', $event)->assertOk();
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertNotNull(Order::where('uuid', $order['uuid'])->firstOrFail()->paid_at);
        Bus::assertDispatchedTimes(ProvisionPaidOrder::class, 1);
    }

    public function test_mismatched_provider_amount_never_marks_order_paid(): void
    {
        $this->setupProvider();
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'order_number' => 'ORD-TEST-CLICKPESA',
            'plan_id' => $this->plan()->id, 'customer_phone' => '255700000001',
            'amount' => 500, 'currency' => 'TZS', 'status' => 'pending_payment',
        ]);
        $payment = Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'clickpesa',
            'reference' => 'R1234567890123456789', 'status' => 'pending', 'amount' => 500,
            'currency' => 'TZS', 'idempotency_key' => 'R1234567890123456789',
        ]);
        Http::fake(function ($request) use ($payment) {
            if (str_ends_with($request->url(), '/generate-token')) return Http::response(['success' => true, 'token' => 'Bearer test-token']);
            return Http::response([[
                'id' => 'cp-mismatch', 'status' => 'SUCCESS', 'orderReference' => $payment->reference,
                'clientId' => 'test-client', 'collectedAmount' => 501, 'collectedCurrency' => 'TZS',
                'paymentPhoneNumber' => '255700000001',
            ]]);
        });
        $event = ['event' => 'PAYMENT RECEIVED', 'data' => ['orderReference' => $payment->reference]];
        $event['checksum'] = $this->checksum($event);
        $this->postJson('/api/webhooks/clickpesa', $event)->assertStatus(503);
        $this->assertNull($order->fresh()->paid_at);
        $this->assertSame('pending', $payment->fresh()->status);
        Bus::assertNotDispatched(ProvisionPaidOrder::class);
    }

    private function checksum(array $payload): string
    {
        ksort($payload);
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES), 'test-checksum-key');
    }
}
