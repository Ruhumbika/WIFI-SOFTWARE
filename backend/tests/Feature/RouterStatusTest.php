<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminController;
use App\Models\HotspotSession;
use App\Models\Plan;
use App\Models\Voucher;
use App\Services\MikrotikRestClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_hotspot_profile_uses_routeros_set_with_literal_id(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'ONE',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_ONE', 'active' => true,
        ]);
        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/ip/hotspot/user/profile')) {
                return Http::response([['.id' => '*11', 'name' => 'RJAY_ONE']], 200);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/ip/hotspot/user/profile/set')) {
                return Http::response([], 200);
            }
            return Http::response(['error' => 500], 500);
        });

        $profile = app(MikrotikRestClient::class)->ensureProfile($plan);

        $this->assertSame('*11', $profile['.id']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ip/hotspot/user/profile/set')
            && $request['.id'] === '*11'
            && $request['name'] === 'RJAY_ONE');
        Http::assertNotSent(fn ($request) => $request->method() === 'PATCH');
    }

    public function test_router_health_reports_configured_hotspot_and_live_users(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        config()->set('mikrotik.hotspot_server', 'hotspot1');
        Cache::put('rjay:hotspot:last-successful-sync', '2026-09-20T10:00:00+03:00');
        Http::fake([
            '*/rest/system/resource' => Http::response(['version' => '7.20', 'board-name' => 'hAP'], 200),
            '*/rest/system/identity' => Http::response([['name' => 'RJAY Router']], 200),
            '*/rest/ip/hotspot/active' => Http::response([['user' => 'RJAY-ONE']], 200),
            '*/rest/ip/hotspot' => Http::response([['name' => 'hotspot1', 'disabled' => 'no', 'interface' => 'bridge1']], 200),
        ]);

        $status = app(AdminController::class)->routerHealth(app(MikrotikRestClient::class));

        $this->assertTrue($status['connected']);
        $this->assertTrue($status['hotspot']);
        $this->assertSame('RJAY Router', $status['router_name']);
        $this->assertSame(1, $status['active_users']);
        $this->assertSame('bridge1', $status['hotspot_server']['interface']);
        $this->assertSame('2026-09-20T10:00:00+03:00', $status['last_sync']);
    }

    public function test_failed_router_probe_reports_unavailable_without_response_body(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        Http::fake(['*' => Http::response('private router output', 404)]);

        $response = app(AdminController::class)->routerHealth(app(MikrotikRestClient::class));

        $this->assertSame(503, $response->status());
        $this->assertFalse($response->getData(true)['connected']);
        $this->assertStringNotContainsString('private router output', $response->getContent());
    }

    public function test_successful_empty_session_sync_records_completion_time(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        Http::fake(['*/rest/ip/hotspot/active' => Http::response([], 200)]);

        $this->artisan('rjay:sync-hotspot')->assertExitCode(0);

        $this->assertNotNull(Cache::get('rjay:hotspot:last-successful-sync'));
    }

    public function test_active_voucher_sync_binds_mac_with_routeros_set_and_records_session(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'ONE',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_ONE', 'active' => true,
        ]);
        $voucher = Voucher::create([
            'uuid' => (string) Str::uuid(), 'code' => 'RJAY-TEST01', 'secret' => 'test-only',
            'plan_id' => $plan->id, 'status' => 'ready', 'mikrotik_id' => '*33A',
        ]);
        Http::fake([
            '*/rest/ip/hotspot/active' => Http::response([[
                '.id' => '*17', 'user' => $voucher->code, 'mac-address' => 'AA:BB:CC:DD:EE:FF',
                'address' => '10.10.1.23', 'uptime' => '1m',
            ]], 200),
            '*/rest/ip/hotspot/user/set' => Http::response([], 200),
        ]);

        $this->artisan('rjay:sync-hotspot')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ip/hotspot/user/set')
            && $request['.id'] === '*33A'
            && $request['mac-address'] === 'AA:BB:CC:DD:EE:FF');
        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $voucher->fresh()->device_mac);
        $this->assertSame(1, HotspotSession::count());
    }

    public function test_disconnect_uses_routeros_remove_when_delete_fails_and_confirms_logout(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'ONE',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_ONE', 'active' => true,
        ]);
        $voucher = Voucher::create([
            'uuid' => (string) Str::uuid(), 'code' => 'RJAY-TEST01', 'secret' => 'test-only',
            'plan_id' => $plan->id, 'status' => 'active', 'mikrotik_id' => '*33A',
        ]);
        $session = HotspotSession::create([
            'voucher_id' => $voucher->id, 'mikrotik_id' => '*A0A01FA',
            'mac_address' => 'AA:BB:CC:DD:EE:FF', 'started_at' => now(), 'last_seen_at' => now(),
        ]);
        $reads = 0;
        $cookieReads = 0;
        Http::fake(function ($request) use (&$reads, &$cookieReads, $voucher) {
            if (str_ends_with($request->url(), '/ip/hotspot/active')) {
                $reads++;
                return Http::response($reads === 1 ? [[
                    '.id' => '*A0A01FA', 'user' => $voucher->code,
                    'mac-address' => 'AA:BB:CC:DD:EE:FF',
                ]] : [], 200);
            }
            if (str_ends_with($request->url(), '/ip/hotspot/user')) return Http::response([[
                '.id' => '*33A', 'name' => $voucher->code, 'disabled' => 'true',
            ]], 200);
            if ($request->method() === 'PATCH' && str_contains($request->url(), '/ip/hotspot/user/')) {
                return Http::response(['error' => 500], 500);
            }
            if (str_ends_with($request->url(), '/ip/hotspot/user/set')) return Http::response([], 200);
            if (str_contains($request->url(), '/ip/hotspot/user/')) return Http::response([], 200);
            if (str_ends_with($request->url(), '/ip/hotspot/cookie')) {
                $cookieReads++;
                return Http::response($cookieReads === 1 ? [[
                    '.id' => '*C1', 'user' => $voucher->code, 'mac-cookie' => 'true',
                ]] : [], 200);
            }
            if ($request->method() === 'DELETE') return Http::response(['error' => 500], 500);
            if (str_ends_with($request->url(), '/ip/hotspot/active/remove')) return Http::response([], 200);
            if (str_ends_with($request->url(), '/ip/hotspot/cookie/remove')) return Http::response([], 200);
            return Http::response([], 404);
        });

        $response = app(AdminController::class)->disconnectSession($session, app(MikrotikRestClient::class));

        $this->assertSame(2, $reads);
        $this->assertSame(2, $cookieReads);
        $this->assertSame('Voucher disabled and all its sessions disconnected. It cannot be used again.', $response['message']);
        $this->assertSame('disabled', $voucher->fresh()->status);
        $this->assertNotNull($session->fresh()->ended_at);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ip/hotspot/active/remove')
            && $request['.id'] === '*A0A01FA');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ip/hotspot/cookie/remove')
            && $request['.id'] === '*C1');
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/ip/hotspot/user/')
            && $request['disabled'] === 'yes');
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/ip/hotspot/user/set')
            && $request['.id'] === '*33A'
            && $request['disabled'] === 'yes');
    }

    public function test_disconnect_keeps_session_open_when_router_remove_fails(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'ONE',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_ONE', 'active' => true,
        ]);
        $voucher = Voucher::create([
            'uuid' => (string) Str::uuid(), 'code' => 'RJAY-TEST02', 'secret' => 'test-only',
            'plan_id' => $plan->id, 'status' => 'active', 'mikrotik_id' => '*33B',
        ]);
        $session = HotspotSession::create([
            'voucher_id' => $voucher->id, 'mikrotik_id' => '*B0B01FB',
            'mac_address' => 'AA:BB:CC:DD:EE:FF', 'started_at' => now(), 'last_seen_at' => now(),
        ]);
        Http::fake(function ($request) use ($voucher) {
            if (str_ends_with($request->url(), '/ip/hotspot/active')) return Http::response([[
                '.id' => '*B0B01FB', 'user' => $voucher->code,
                'mac-address' => 'AA:BB:CC:DD:EE:FF',
            ]], 200);
            if (str_ends_with($request->url(), '/ip/hotspot/user')) return Http::response([[
                '.id' => '*33B', 'name' => $voucher->code, 'disabled' => 'true',
            ]], 200);
            if (str_contains($request->url(), '/ip/hotspot/user/')) return Http::response([], 200);
            return Http::response(['error' => 500], 500);
        });

        $response = app(AdminController::class)->disconnectSession($session, app(MikrotikRestClient::class));

        $this->assertSame(503, $response->status());
        $this->assertSame('disabled', $voucher->fresh()->status);
        $this->assertNull($session->fresh()->ended_at);
        $this->assertStringNotContainsString('500', $response->getContent());
    }

    public function test_sync_does_not_reactivate_blocked_voucher_seen_on_router(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'ONE',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_ONE', 'active' => true,
        ]);
        $voucher = Voucher::create([
            'uuid' => (string) Str::uuid(), 'code' => 'RJAY-BLOCKED', 'secret' => 'test-only',
            'plan_id' => $plan->id, 'status' => 'disabled', 'mikrotik_id' => '*33C',
        ]);
        Http::fake([
            '*/rest/ip/hotspot/active' => Http::response([[
                '.id' => '*18', 'user' => $voucher->code, 'mac-address' => 'AA:BB:CC:DD:EE:FF',
            ]], 200),
            '*/rest/ip/hotspot/active/*' => Http::response([], 200),
            '*/rest/ip/hotspot/user/*' => Http::response([], 200),
        ]);

        $this->artisan('rjay:sync-hotspot')->assertExitCode(0);

        $this->assertSame('disabled', $voucher->fresh()->status);
        $this->assertNull($voucher->fresh()->activated_at);
        $this->assertSame(0, HotspotSession::count());
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/ip/hotspot/active/'));
        Http::assertSent(fn ($request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/ip/hotspot/user/')
            && $request['disabled'] === 'yes');
    }

    public function test_disconnect_does_not_report_success_when_router_user_stays_enabled(): void
    {
        config()->set('mikrotik.base_url', 'http://router.test/rest');
        config()->set('mikrotik.password', 'test-only');
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'One hour', 'code' => 'ONE',
            'price' => 500, 'currency' => 'TZS', 'duration_seconds' => 3600,
            'rate_limit' => '2M/2M', 'mikrotik_profile_name' => 'RJAY_ONE', 'active' => true,
        ]);
        $voucher = Voucher::create([
            'uuid' => (string) Str::uuid(), 'code' => 'RJAY-STILLON', 'secret' => 'test-only',
            'plan_id' => $plan->id, 'status' => 'active', 'mikrotik_id' => '*33D',
        ]);
        $session = HotspotSession::create([
            'voucher_id' => $voucher->id, 'mikrotik_id' => '*19',
            'mac_address' => 'AA:BB:CC:DD:EE:FF', 'started_at' => now(), 'last_seen_at' => now(),
        ]);
        Http::fake([
            '*/rest/ip/hotspot/user' => Http::response([[
                '.id' => '*33D', 'name' => $voucher->code, 'disabled' => 'no',
            ]], 200),
            '*/rest/ip/hotspot/user/*' => Http::response([], 200),
        ]);

        $response = app(AdminController::class)->disconnectSession($session, app(MikrotikRestClient::class));

        $this->assertSame(503, $response->status());
        $this->assertSame('active', $voucher->fresh()->status);
        $this->assertNull($session->fresh()->ended_at);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ip/hotspot/active/'));
    }
}
