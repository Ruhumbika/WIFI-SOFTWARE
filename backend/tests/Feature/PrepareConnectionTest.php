<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\MikrotikRestClient;
use App\Services\VoucherProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Tests\TestCase;

class PrepareConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function orderHeaders(Order $order): array
    {
        return ['X-Order-Token' => Crypt::encryptString($order->uuid)];
    }

    private function paidOrder(string $voucherStatus = 'ready'): Order
    {
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One day', 'code' => 'DAY',
            'price' => 2000, 'currency' => 'TZS', 'duration_seconds' => 86400,
            'rate_limit' => '4M/4M', 'mikrotik_profile_name' => 'RJAY_DAY', 'active' => true,
        ]);
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'order_number' => 'ORD-TEST-1',
            'plan_id' => $plan->id, 'customer_phone' => '255700000001',
            'amount' => 2000, 'currency' => 'TZS', 'status' => 'completed', 'paid_at' => now(),
        ]);
        Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'provider' => 'snippe',
            'status' => 'completed', 'amount' => 2000, 'currency' => 'TZS',
            'idempotency_key' => 'test-payment-1',
        ]);
        Voucher::create([
            'uuid' => (string) Str::uuid(), 'code' => 'RJAY-TEST1', 'secret' => '123456',
            'plan_id' => $plan->id, 'order_id' => $order->id, 'status' => $voucherStatus,
        ]);
        return $order;
    }

    public function test_paid_order_completes_when_its_voucher_becomes_ready(): void
    {
        $order = $this->paidOrder('provision_pending');
        $order->forceFill(['status' => 'provisioning'])->save();
        $this->mock(MikrotikRestClient::class)->shouldReceive('createVoucherUser')->once()
            ->andReturn(['.id' => '*11']);

        app(VoucherProvisioner::class)->provision($order->voucher);

        $this->assertSame('ready', $order->voucher->fresh()->status);
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->completed_at);
    }

    public function test_provisioning_does_not_mark_an_unpaid_order_complete(): void
    {
        $order = $this->paidOrder('provision_pending');
        $order->forceFill(['status' => 'pending_payment', 'paid_at' => null])->save();
        $order->payments()->update(['status' => 'pending']);
        $this->mock(MikrotikRestClient::class)->shouldReceive('createVoucherUser')->once()
            ->andReturn(['.id' => '*12']);

        app(VoucherProvisioner::class)->provision($order->voucher);

        $this->assertSame('ready', $order->voucher->fresh()->status);
        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertNull($order->fresh()->completed_at);
    }

    public function test_paid_order_reports_preparation_timeout_without_changing_payment_status(): void
    {
        $order = $this->paidOrder('provision_pending');
        $order->forceFill(['status' => 'provisioning', 'paid_at' => now()->subMinutes(7)])->save();
        $url = "/api/public/orders/{$order->uuid}";

        $this->getJson($url, $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('preparation_timed_out', true)
            ->assertJsonPath('payment.status', 'completed');

        $order->forceFill(['paid_at' => now()->subMinutes(5)])->save();
        $this->getJson($url, $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('preparation_timed_out', false);

        $order->forceFill(['paid_at' => now()->subMinutes(7)])->save();
        $order->voucher->forceFill(['status' => 'ready'])->save();
        $this->getJson($url, $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('preparation_timed_out', false);
    }

    public function test_only_confirmed_payment_can_prepare_connection(): void
    {
        $order = $this->paidOrder();
        $order->payments()->update(['status' => 'pending']);
        $order->forceFill(['status' => 'pending_payment', 'paid_at' => null])->save();

        $this->postJson("/api/public/orders/{$order->uuid}/prepare-connection", [], $this->orderHeaders($order))
            ->assertStatus(409)->assertJsonPath('state', 'payment_required');
    }

    public function test_ready_voucher_only_returns_a_login_url_on_the_configured_router(): void
    {
        $order = $this->paidOrder();
        config()->set('mikrotik.password', 'test-only');
        Http::fake(['*' => Http::response(['version' => '7'], 200)]);

        $this->postJson("/api/public/orders/{$order->uuid}/prepare-connection", [
            'login_url' => 'http://10.10.1.1/login',
        ], $this->orderHeaders($order))->assertOk()->assertJsonPath('state', 'ready')->assertJsonPath('login_url', 'http://10.10.1.1/login');

        $this->postJson("/api/public/orders/{$order->uuid}/prepare-connection", [
            'login_url' => 'https://other.example/login',
        ], $this->orderHeaders($order))->assertOk()->assertJsonPath('login_url', null);
    }

    public function test_bound_device_mismatch_is_reported_before_router_login(): void
    {
        $order = $this->paidOrder('active');
        $order->voucher->forceFill(['device_mac' => 'AA:BB:CC:DD:EE:01'])->save();

        $this->postJson("/api/public/orders/{$order->uuid}/prepare-connection", [
            'device_mac' => 'AA:BB:CC:DD:EE:02',
        ], $this->orderHeaders($order))->assertStatus(409)->assertJsonPath('state', 'device_mismatch');
    }

    public function test_expired_voucher_never_attempts_router_login(): void
    {
        $order = $this->paidOrder('expired');
        Http::fake();

        $this->postJson("/api/public/orders/{$order->uuid}/prepare-connection", [], $this->orderHeaders($order))
            ->assertStatus(409)->assertJsonPath('state', 'expired');
        Http::assertNothingSent();
    }

    public function test_router_failure_keeps_paid_voucher_available_for_retry(): void
    {
        $order = $this->paidOrder();
        config()->set('mikrotik.password', 'test-only');
        Http::fake(['*' => Http::response(['message' => 'unavailable'], 503)]);

        $this->postJson("/api/public/orders/{$order->uuid}/prepare-connection", [], $this->orderHeaders($order))
            ->assertStatus(503)->assertJsonPath('state', 'router_unavailable');
        $this->assertDatabaseHas('vouchers', ['order_id' => $order->id, 'status' => 'ready']);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'completed']);
    }

    public function test_order_url_alone_cannot_retrieve_voucher_secret(): void
    {
        $order = $this->paidOrder();
        $this->getJson("/api/public/orders/{$order->uuid}")->assertForbidden();
        $this->getJson("/api/public/orders/{$order->uuid}", $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('voucher.password', '123456');
    }

    public function test_connection_status_requires_order_token_and_live_router_session(): void
    {
        $order = $this->paidOrder('active');
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $order->voucher->forceFill(['device_mac' => 'AA:BB:CC:DD:EE:01'])->save();
        Http::fake(['*/rest/ip/hotspot/active' => Http::response([[
            'user' => 'RJAY-TEST1', 'mac-address' => 'AA:BB:CC:DD:EE:01',
        ]], 200)]);

        $url = "/api/public/orders/{$order->uuid}/connection";
        $this->getJson($url)->assertForbidden();
        $this->getJson($url.'?device_mac=AA%3ABB%3ACC%3ADD%3AEE%3A01', $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('state', 'online');
        $this->getJson($url.'?device_mac=AA%3ABB%3ACC%3ADD%3AEE%3A02', $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('state', 'device_mismatch');
    }

    public function test_blocked_voucher_reports_unavailable_without_claiming_router_access(): void
    {
        $order = $this->paidOrder('disabled');
        Http::fake();

        $this->getJson("/api/public/orders/{$order->uuid}/connection", $this->orderHeaders($order))
            ->assertOk()->assertJsonPath('state', 'unavailable');
        Http::assertNothingSent();
    }

    public function test_connection_status_reports_offline_and_router_failure_honestly(): void
    {
        $order = $this->paidOrder('active');
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $url = "/api/public/orders/{$order->uuid}/connection";
        Http::fake(['*/rest/ip/hotspot/active' => Http::sequence()
            ->push([], 200)
            ->push(['error' => 503], 503)]);
        $this->getJson($url, $this->orderHeaders($order))->assertOk()->assertJsonPath('state', 'offline');
        $this->getJson($url, $this->orderHeaders($order))->assertStatus(503)
            ->assertJsonPath('state', 'router_unavailable');
    }

    public function test_order_creation_returns_a_token_that_recovers_the_order(): void
    {
        $plan = $this->paidOrder()->plan;
        $created = $this->postJson('/api/public/orders', [
            'plan_id' => $plan->id, 'phone' => '0700000002',
            'name' => 'Test Customer', 'email' => 'customer@example.test',
        ])->assertCreated()->json();

        $this->getJson('/api/public/orders/'.$created['uuid'], [
            'X-Order-Token' => $created['access_token'],
        ])->assertOk()->assertJsonPath('status', 'pending_payment');
    }
}
