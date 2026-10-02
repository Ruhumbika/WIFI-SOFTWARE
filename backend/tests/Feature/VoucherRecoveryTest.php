<?php

namespace Tests\Feature;

use App\Jobs\ProvisionPaidOrder;
use App\Models\AdminApiToken;
use App\Models\HotspotSession;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherDeviceOperation;
use App\Services\MikrotikRestClient;
use App\Services\PhoneNormalizer;
use App\Services\VoucherAccessService;
use App\Services\VoucherProvisioner;
use App\Services\VoucherRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VoucherRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function voucher(array $fields = []): Voucher
    {
        $plan = Plan::first() ?: Plan::create(['uuid' => (string) Str::uuid(), 'name' => 'Day', 'code' => 'DAY', 'price' => 2000, 'currency' => 'TZS', 'duration_seconds' => 86400, 'rate_limit' => '4M/4M', 'mikrotik_profile_name' => 'RJAY_DAY', 'active' => true]);

        return Voucher::create(array_merge(['uuid' => (string) Str::uuid(), 'code' => 'RJAY-' . Str::upper(Str::random(6)), 'secret' => '123456', 'plan_id' => $plan->id, 'status' => 'ready', 'customer_phone' => '255712345678', 'mikrotik_id' => '*11'], $fields));
    }

    private function owner(Voucher $v): array
    {
        return ['X-Voucher-Recovery-Token' => app(VoucherAccessService::class)->token($v)];
    }

    private function admin(): array
    {
        $user = User::create(['name' => 'Operator', 'email' => Str::random(8) . '@example.test', 'password' => 'test-only']);
        $token = Str::random(40);
        AdminApiToken::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);

        return ['Authorization' => 'Bearer ' . $token];
    }

    public function test_tanzania_phone_formats_and_invalid_values(): void
    {
        foreach (['0712345678', '712345678', '+255712345678', '255712345678'] as $phone) {
            $this->assertSame('255712345678', PhoneNormalizer::normalize($phone));
        }
        $this->expectException(ValidationException::class);
        PhoneNormalizer::normalize('bad0712345678');
    }

    public function test_lookup_masks_credentials_mac_and_phone(): void
    {
        $v = $this->voucher(['device_mac' => 'AA:BB:CC:DD:EE:FF']);
        $response = $this->postJson('/api/public/vouchers/recovery/lookup', ['phone' => '0712345678'])->assertOk();
        $this->assertStringNotContainsString($v->code, $response->getContent());
        foreach (['123456', 'AA:BB:CC:DD:EE:FF', '255712345678', 'password', 'secret', 'recovery_pin_hash'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $response->assertJsonPath('vouchers.0.device_mac', '••:••:••:••:EE:FF');
    }

    public function test_pin_is_hashed_one_time_and_order_token_is_required(): void
    {
        $v = $this->voucher();
        $order = Order::create(['uuid' => (string) Str::uuid(), 'order_number' => 'ORD-TEST', 'plan_id' => $v->plan_id, 'customer_phone' => $v->customer_phone, 'amount' => 2000, 'currency' => 'TZS', 'status' => 'completed', 'paid_at' => now()]);
        $v->update(['order_id' => $order->id]);
        $url = '/api/public/vouchers/' . $v->uuid . '/recovery-pin';
        $this->postJson($url, [], $this->owner($v))->assertForbidden();
        $headers = ['X-Order-Token' => Crypt::encryptString($order->uuid)];
        $pin = $this->postJson($url, [], $headers)->assertOk()->json('recovery_pin');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $pin);
        $this->assertNotSame('123456', $pin);
        $this->assertTrue(Hash::check($pin, $v->fresh()->recovery_pin_hash));
        $this->assertArrayNotHasKey('recovery_pin_hash', $v->fresh()->toArray());
        $this->postJson($url, [], $headers)->assertConflict();
        $this->assertDatabaseCount('voucher_events', 1);
        $this->assertStringNotContainsString($pin, json_encode(DB::table('voucher_events')->get()));
        $this->getJson('/api/public/orders/' . $order->uuid, $headers)->assertOk()->assertJsonPath('voucher.code', $v->code);
    }

    public function test_recovery_token_is_scoped_to_one_voucher_and_expires(): void
    {
        $v = $this->voucher();
        $other = $this->voucher();
        $phoneB = $this->voucher(['customer_phone' => '255754123456']);
        $pin = app(VoucherRecoveryService::class)->issue($v);
        $data = $this->postJson('/api/public/vouchers/recovery/verify', ['phone' => '0712345678', 'voucher_uuid' => $v->uuid, 'recovery_pin' => $pin])->assertOk()->json();
        $headers = ['X-Voucher-Recovery-Token' => $data['recovery_token']];
        $this->getJson('/api/public/vouchers/mine?voucher_uuid=' . $v->uuid, $headers)->assertOk()->assertJsonCount(1, 'vouchers');
        foreach ([$other, $phoneB] as $denied) {
            $this->getJson('/api/public/vouchers/' . $denied->uuid, $headers)->assertForbidden();
        }
        $this->postJson('/api/public/vouchers/recovery/verify', ['phone' => '0754123456', 'voucher_uuid' => $v->uuid, 'recovery_pin' => $pin])->assertUnprocessable();
        $this->travel(16)->minutes();
        $this->getJson('/api/public/vouchers/' . $v->uuid, $headers)->assertForbidden();
    }

    public function test_wrong_recovery_pin_and_rate_limit(): void
    {
        $v = $this->voucher();
        app(VoucherRecoveryService::class)->issue($v);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/vouchers/recovery/verify', ['phone' => '0712345678', 'voucher_uuid' => $v->uuid, 'recovery_pin' => 'abcdef'])->assertUnprocessable();
        }
        $this->postJson('/api/public/vouchers/recovery/verify', ['phone' => '0712345678', 'voucher_uuid' => $v->uuid, 'recovery_pin' => '000000'])->assertStatus(429);
    }

    public function test_valid_wrong_expired_disabled_and_bound_redemption(): void
    {
        $v = $this->voucher();
        $this->mock(MikrotikRestClient::class)->shouldReceive('resource')->andReturn([]);
        $url = '/api/public/vouchers/redeem';
        $input = ['code' => strtolower($v->code) . ' ', 'pin' => '123456'];
        $this->postJson($url, $input)->assertOk()->assertJsonPath('state', 'ready');
        $this->postJson($url, array_merge($input, ['pin' => 'wrong']))->assertUnprocessable();
        $this->postJson($url, ['code' => 'RJAY-WRONG', 'pin' => '123456'])->assertUnprocessable();
        foreach (['expired', 'disabled', 'revoked'] as $status) {
            $v->update(['status' => $status]);
            $this->postJson($url, $input)->assertOk()->assertJsonPath('state', $status === 'expired' ? 'expired' : 'unavailable');
        }
        $v->update(['status' => 'active', 'device_mac' => 'AA:BB:CC:DD:EE:01', 'activated_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        $before = $v->expires_at->toISOString();
        $this->postJson($url, array_merge($input, ['device_mac' => 'AA:BB:CC:DD:EE:01']))->assertOk()->assertJsonPath('state', 'active');
        for ($i = 0; $i < 2; $i++) {
            $this->postJson($url, array_merge($input, ['device_mac' => 'AA:BB:CC:DD:EE:02']))->assertOk()->assertJsonPath('state', 'device_mismatch');
        }
        $this->assertSame(1, DB::table('voucher_events')->where('event_type', 'device_mismatch')->count());
        $this->assertSame($before, $v->fresh()->expires_at->toISOString());
    }

    public function test_client_mac_is_not_ownership_proof_and_redeem_token_cannot_transfer(): void
    {
        $v = $this->voucher(['device_mac' => 'AA:BB:CC:DD:EE:01']);
        $this->getJson('/api/public/vouchers/' . $v->uuid . '?device_mac=AA:BB:CC:DD:EE:01')->assertForbidden();
        $token = app(VoucherAccessService::class)->token($v, 'redeem');
        $this->postJson('/api/public/vouchers/' . $v->uuid . '/device-transfer-request', [], ['X-Voucher-Recovery-Token' => $token])->assertForbidden();
    }

    public function test_claim_is_one_time_and_does_not_steal_registered_voucher(): void
    {
        $v = $this->voucher(['customer_phone' => null]);
        $input = ['code' => $v->code, 'pin' => '123456', 'phone' => '0712345678'];
        $response = $this->postJson('/api/public/vouchers/claim', $input)->assertOk();
        $this->assertTrue(Hash::check($response->json('recovery_pin'), $v->fresh()->recovery_pin_hash));
        $this->postJson('/api/public/vouchers/claim', array_merge($input, ['phone' => '0754123456']))->assertConflict();
        $this->assertSame('255712345678', $v->fresh()->customer_phone);
    }

    public function test_transfer_is_unique_and_release_is_idempotent_preserving_dates(): void
    {
        $v = $this->voucher(['status' => 'active', 'device_mac' => 'AA:BB:CC:DD:EE:01', 'activated_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        $headers = $this->owner($v);
        $url = '/api/public/vouchers/' . $v->uuid . '/device-transfer-request';
        $this->postJson($url, [], $headers)->assertOk();
        $this->postJson($url, [], $headers)->assertOk();
        $this->assertDatabaseCount('voucher_device_transfer_requests', 1);
        $request = DB::table('voucher_device_transfer_requests')->first();
        HotspotSession::create(['voucher_id' => $v->id, 'mikrotik_id' => '*A', 'mac_address' => $v->device_mac, 'started_at' => now()->subHour(), 'last_seen_at' => now()]);
        $this->mock(MikrotikRestClient::class)->shouldReceive('reconcileVoucherDevice')->once()->withArgs(fn($voucher, $action, $secret) => $voucher->id === $v->id && $action === 'release' && $secret === null);
        $key = (string) Str::uuid();
        $admin = $this->admin();
        $input = ['request_key' => $key, 'transfer_request_id' => $request->id];
        $endpoint = '/api/admin/vouchers/' . $v->id . '/transfer/approve';
        $this->postJson($endpoint, $input, $admin)->assertOk()->assertJsonPath('state', 'completed');
        $this->postJson($endpoint, $input, $admin)->assertOk();
        $fresh = $v->fresh();
        $this->assertNull($fresh->device_mac);
        $this->assertSame(1, $fresh->transfer_count);
        $this->assertTrue($fresh->expires_at->equalTo($v->expires_at));
        $this->assertTrue($fresh->activated_at->equalTo($v->activated_at));
        $this->assertNotNull($fresh->sessions()->first()->ended_at);
        $this->assertDatabaseHas('voucher_events', ['voucher_id' => $v->id, 'event_type' => 'device_transfer_approved']);
        $this->assertDatabaseHas('voucher_device_transfer_requests', ['id' => $request->id, 'status' => 'approved']);
    }

    public function test_rotation_partial_failure_retries_same_secret_invalidates_access_and_preserves_terms(): void
    {
        $v = $this->voucher(['status' => 'active', 'activated_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        $oldHeaders = $this->owner($v);
        $attempts = [];
        $this->mock(MikrotikRestClient::class)->shouldReceive('reconcileVoucherDevice')->twice()->andReturnUsing(function ($voucher, $action, $secret) use (&$attempts) {
            $attempts[] = $secret;
            if (count($attempts) === 1) {
                throw new \RuntimeException('Router response lost');
            }
        });
        $key = (string) Str::uuid();
        $admin = $this->admin();
        $url = '/api/admin/vouchers/' . $v->id . '/rotate-credentials';
        $this->postJson($url, ['request_key' => $key], $admin)->assertStatus(202)->assertJsonPath('state', 'pending_reconciliation');
        $this->assertSame('123456', $v->fresh()->secret);
        $this->postJson($url, ['request_key' => $key], $admin)->assertOk();
        $this->assertSame($attempts[0], $attempts[1]);
        $this->assertSame($attempts[0], $v->fresh()->secret);
        $this->assertNotSame('123456', $attempts[0]);
        $this->assertTrue($v->fresh()->expires_at->equalTo($v->expires_at));
        $this->getJson('/api/public/vouchers/' . $v->uuid, $oldHeaders)->assertForbidden();
        $this->assertNull(VoucherDeviceOperation::first()->target_secret);
        $this->postJson('/api/public/vouchers/redeem', ['code' => $v->code, 'pin' => '123456'])->assertUnprocessable();
    }

    public function test_pin_reset_invalidates_existing_recovery_tokens(): void
    {
        $v = $this->voucher();
        $pin = app(VoucherRecoveryService::class)->issue($v);
        $v->refresh();
        $headers = $this->owner($v);
        app(VoucherRecoveryService::class)->issue($v, true);
        $this->getJson('/api/public/vouchers/' . $v->uuid, $headers)->assertForbidden();
        $this->assertFalse(Hash::check($pin, $v->fresh()->recovery_pin_hash));
    }

    public function test_paid_provisioning_does_not_bind_purchase_mac(): void
    {
        $v = $this->voucher();
        $v->delete();
        $order = Order::create(['uuid' => (string) Str::uuid(), 'order_number' => 'ORD-MAC', 'plan_id' => $v->plan_id, 'customer_phone' => '255712345678', 'device_mac' => 'AA:BB:CC:DD:EE:01', 'amount' => 2000, 'currency' => 'TZS', 'status' => 'paid', 'paid_at' => now()]);
        $this->mock(MikrotikRestClient::class)->shouldReceive('createVoucherUser')->once()->withArgs(fn($voucher) => $voucher->device_mac === null)->andReturn(['.id' => '*11']);
        (new ProvisionPaidOrder($order->id))->handle(app(VoucherProvisioner::class));
        $this->assertNull($order->fresh()->voucher->device_mac);
        $this->assertNull($order->fresh()->voucher->activated_at);
    }

    public function test_successful_session_binds_once_and_reconnect_keeps_validity(): void
    {
        $v = $this->voucher();
        $mock = $this->mock(MikrotikRestClient::class);
        $mock->shouldReceive('activeSessions')->twice()->andReturn([['.id' => '*A', 'user' => $v->code, 'mac-address' => 'AA:BB:CC:DD:EE:01']]);
        $mock->shouldReceive('bindMac')->once();
        $this->artisan('rjay:sync-hotspot')->assertSuccessful();
        $first = $v->fresh();
        $this->travel(5)->minutes();
        $this->artisan('rjay:sync-hotspot')->assertSuccessful();
        $this->assertSame('AA:BB:CC:DD:EE:01', $first->device_mac);
        $this->assertTrue($first->activated_at->equalTo($v->fresh()->activated_at));
        $this->assertTrue($first->expires_at->equalTo($v->fresh()->expires_at));
        $this->assertSame(1, DB::table('voucher_events')->where('event_type', 'device_bound')->count());
    }

    public function test_registered_and_anonymous_manual_generation(): void
    {
        $v = $this->voucher();
        $this->mock(MikrotikRestClient::class)->shouldReceive('createVoucherUser')->andReturn(['.id' => '*12']);
        $admin = $this->admin();
        $this->postJson('/api/admin/vouchers/generate', ['plan_id' => $v->plan_id, 'quantity' => 1], $admin)->assertCreated()->assertJsonPath('0.customer_phone', null)->assertJsonPath('0.recovery_pin', null);
        $result = $this->postJson('/api/admin/vouchers/generate', ['plan_id' => $v->plan_id, 'quantity' => 1, 'phone' => '0712345678'], $admin)->assertCreated();
        $this->assertTrue(Hash::check($result->json('0.recovery_pin'), Voucher::find($result->json('0.id'))->recovery_pin_hash));
        $this->assertStringNotContainsString('recovery_pin_hash', $result->getContent());
    }

    public function test_additive_migration_rolls_back_without_removing_existing_vouchers(): void
    {
        $v = $this->voucher();
        $migration = require database_path('migrations/2026_09_27_000001_add_voucher_recovery_and_events.php');
        $migration->down();
        $this->assertDatabaseHas('vouchers', ['id' => $v->id, 'code' => $v->code]);
        $migration->up();
        $this->assertDatabaseHas('vouchers', ['id' => $v->id, 'code' => $v->code]);
    }

    public function test_wrong_six_digit_pin_is_audited_and_locked_out(): void
    {
        $v = $this->voucher();
        $pin = app(VoucherRecoveryService::class)->issue($v);
        $wrong = $pin === '000000' ? '000001' : '000000';
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/public/vouchers/recovery/verify', ['phone' => '0712345678', 'voucher_uuid' => $v->uuid, 'recovery_pin' => $wrong])->assertUnprocessable();
        }
        $this->assertSame(5, DB::table('voucher_events')->where('event_type', 'recovery_failed')->count());
        $this->travel(2)->minutes();
        $this->postJson('/api/public/vouchers/recovery/verify', ['phone' => '0712345678', 'voucher_uuid' => $v->uuid, 'recovery_pin' => $pin])->assertStatus(429);
    }

    public function test_rejected_transfer_and_compromise_report_are_audited_without_releasing_device(): void
    {
        $v = $this->voucher(['device_mac' => 'AA:BB:CC:DD:EE:01']);
        $owner = $this->owner($v);
        $this->postJson('/api/public/vouchers/' . $v->uuid . '/device-transfer-request', [], $owner)->assertOk();
        $request = DB::table('voucher_device_transfer_requests')->first();
        $this->postJson('/api/admin/vouchers/' . $v->id . '/transfer/reject', ['transfer_request_id' => $request->id], $this->admin())->assertOk();
        $this->assertSame($v->device_mac, $v->fresh()->device_mac);
        $this->assertDatabaseHas('voucher_events', ['voucher_id' => $v->id, 'event_type' => 'device_transfer_rejected']);
        $this->postJson('/api/public/vouchers/' . $v->uuid . '/report-compromised', [], $owner)->assertOk();
        $this->assertNotNull($v->fresh()->compromised_at);
        $this->assertSame('123456', $v->fresh()->secret);
    }

    public function test_released_active_voucher_binds_new_device_without_new_validity(): void
    {
        $v = $this->voucher(['status' => 'active', 'device_mac' => null, 'activated_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        $mock = $this->mock(MikrotikRestClient::class);
        $mock->shouldReceive('activeSessions')->once()->andReturn([['.id' => '*A', 'user' => $v->code, 'mac-address' => 'AA:BB:CC:DD:EE:02']]);
        $mock->shouldReceive('bindMac')->once();
        $this->artisan('rjay:sync-hotspot')->assertSuccessful();
        $this->assertSame('AA:BB:CC:DD:EE:02', $v->fresh()->device_mac);
        $this->assertTrue($v->activated_at->equalTo($v->fresh()->activated_at));
        $this->assertTrue($v->expires_at->equalTo($v->fresh()->expires_at));
    }

    public function test_mismatch_is_audited_even_if_router_cannot_disconnect(): void
    {
        $v = $this->voucher(['device_mac' => 'AA:BB:CC:DD:EE:01']);
        $mock = $this->mock(MikrotikRestClient::class);
        $mock->shouldReceive('activeSessions')->once()->andReturn([['.id' => '*A', 'user' => $v->code, 'mac-address' => 'AA:BB:CC:DD:EE:02']]);
        $mock->shouldReceive('disconnect')->once()->andThrow(new \RuntimeException('Offline'));
        $this->artisan('rjay:sync-hotspot')->assertFailed();
        $this->assertDatabaseHas('voucher_events', ['voucher_id' => $v->id, 'event_type' => 'device_mismatch']);
        $this->assertSame($v->device_mac, $v->fresh()->device_mac);
    }

    public function test_router_offline_does_not_destroy_or_expire_ready_voucher(): void
    {
        $v = $this->voucher();
        $this->mock(MikrotikRestClient::class)->shouldReceive('resource')->once()->andThrow(new \RuntimeException('Offline'));
        $this->postJson('/api/public/vouchers/redeem', ['code' => $v->code, 'pin' => '123456'])->assertOk()->assertJsonPath('state', 'router_unavailable');
        $this->assertSame('ready', $v->fresh()->status);
        $this->assertNull($v->fresh()->activated_at);
    }

    public function test_reprovisioning_active_voucher_does_not_reset_state_or_terms(): void
    {
        $v = $this->voucher(['status' => 'active', 'activated_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
        $this->mock(MikrotikRestClient::class)->shouldNotReceive('createVoucherUser');
        app(VoucherProvisioner::class)->provision($v);
        $this->assertSame('active', $v->fresh()->status);
        $this->assertTrue($v->expires_at->equalTo($v->fresh()->expires_at));
    }

    public function test_manual_generation_rolls_back_when_audit_storage_is_missing(): void
    {
        $existing = $this->voucher();
        $headers = $this->admin();
        $before = Voucher::count();
        \Illuminate\Support\Facades\Schema::drop('voucher_events');
        $this->mock(MikrotikRestClient::class)->shouldNotReceive('createVoucherUser');
        $this->postJson('/api/admin/vouchers/generate', [
            'plan_id' => $existing->plan_id,
            'quantity' => 1,
            'phone' => '0712345678',
        ], $headers)->assertStatus(500);
        $this->assertSame($before, Voucher::count());
    }
}
