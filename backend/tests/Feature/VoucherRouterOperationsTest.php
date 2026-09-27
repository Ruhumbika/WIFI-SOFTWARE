<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Voucher;
use App\Services\VoucherDeviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class VoucherRouterOperationsTest extends TestCase
{
    use RefreshDatabase;

    private function voucher(): Voucher
    {
        $plan = Plan::create(['uuid' => (string) Str::uuid(), 'name' => 'Day', 'code' => 'DAY', 'price' => 2000, 'currency' => 'TZS', 'duration_seconds' => 86400, 'rate_limit' => '4M/4M', 'mikrotik_profile_name' => 'RJAY_DAY', 'active' => true]);

        return Voucher::create(['uuid' => (string) Str::uuid(), 'code' => 'RJAY-TEST01', 'secret' => '123456', 'plan_id' => $plan->id, 'status' => 'active', 'device_mac' => 'AA:BB:CC:DD:EE:01', 'mikrotik_id' => '*11', 'activated_at' => now()->subHour(), 'expires_at' => now()->addHour()]);
    }

    private function router(Voucher $v, array &$user, array &$sessions, array &$cookies, bool $keepCookies = false): void
    {
        config()->set(['mikrotik.base_url' => 'http://router.test/rest', 'mikrotik.username' => 'test', 'mikrotik.password' => 'test']);
        $user = ['.id' => '*11', 'name' => $v->code, 'password' => $v->secret, 'mac-address' => $v->device_mac, 'disabled' => 'false', 'limit-uptime' => '1d', 'uptime' => '1h'];
        $sessions = [['.id' => '*A', 'user' => $v->code]];
        $cookies = [['.id' => '*C', 'user' => $v->code]];
        Http::preventStrayRequests();
        Http::fake(function ($request) use (&$user, &$sessions, &$cookies, $keepCookies) {
            $path = rawurldecode(parse_url($request->url(), PHP_URL_PATH));
            if ($request->method() === 'GET') {
                return Http::response(match ($path) {
                    '/rest/ip/hotspot/user' => [$user],'/rest/ip/hotspot/active' => $sessions,'/rest/ip/hotspot/cookie' => $cookies,default => []
                });
            }
            if ($request->method() === 'PATCH' && $path === '/rest/ip/hotspot/user/*11') {
                $user = array_merge($user, $request->data());

                return Http::response($user);
            }
            if ($request->method() === 'DELETE' && $path === '/rest/ip/hotspot/active/*A') {
                $sessions = [];

                return Http::response([]);
            }
            if ($request->method() === 'DELETE' && $path === '/rest/ip/hotspot/cookie/*C') {
                if (! $keepCookies) {
                    $cookies = [];
                }

return Http::response([]);
            }

            return Http::response([], 400);
        });
    }

    public function test_release_removes_sessions_cookies_and_binding_without_resetting_router_uptime(): void
    {
        $v = $this->voucher();
        $user = $sessions = $cookies = [];
        $this->router($v, $user, $sessions, $cookies);
        $result = app(VoucherDeviceService::class)->operate($v, 'release', (string) Str::uuid(), 1);
        $this->assertSame('completed', $result['state']);
        $this->assertSame([], $sessions);
        $this->assertSame([], $cookies);
        $this->assertSame('00:00:00:00:00:00', $user['mac-address']);
        $this->assertSame('no', $user['disabled']);
        $this->assertSame('1h', $user['uptime']);
        $this->assertSame('1d', $user['limit-uptime']);
        $this->assertTrue($v->expires_at->equalTo($v->fresh()->expires_at));
        Http::assertNotSent(fn ($r) => isset($r['limit-uptime']) || isset($r['uptime']));
    }

    public function test_rotation_updates_only_existing_user_pin_and_rejects_old_pin(): void
    {
        $v = $this->voucher();
        $user = $sessions = $cookies = [];
        $this->router($v, $user, $sessions, $cookies);
        $result = app(VoucherDeviceService::class)->operate($v, 'rotate', (string) Str::uuid(), 1);
        $this->assertSame('completed', $result['state']);
        $this->assertSame($v->code, $user['name']);
        $this->assertNotSame('123456', $user['password']);
        $this->assertSame($v->fresh()->secret, $user['password']);
        $this->assertSame($v->device_mac, $user['mac-address']);
        $this->assertSame('1h', $user['uptime']);
        $this->postJson('/api/public/vouchers/redeem', ['code' => $v->code, 'pin' => '123456'])->assertUnprocessable();
        $this->postJson('/api/public/vouchers/redeem', ['code' => $v->code, 'pin' => $user['password'], 'device_mac' => $v->device_mac])->assertOk()->assertJsonPath('state', 'active');
    }

    public function test_remaining_cookie_prevents_success_and_local_release(): void
    {
        $v = $this->voucher();
        $user = $sessions = $cookies = [];
        $this->router($v, $user, $sessions, $cookies, true);
        $result = app(VoucherDeviceService::class)->operate($v, 'release', (string) Str::uuid(), 1);
        $this->assertSame('pending_reconciliation', $result['state']);
        $this->assertSame($v->device_mac, $v->fresh()->device_mac);
        $this->assertSame(0,$v->fresh()->transfer_count);
        $this->assertSame('yes',$user['disabled']);
    }
}
