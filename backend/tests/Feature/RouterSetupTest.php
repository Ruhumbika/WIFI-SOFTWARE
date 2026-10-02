<?php

namespace Tests\Feature;

use App\Models\AdminApiToken;
use App\Models\User;
use App\Services\RouterEnvironment;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterSetupTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $token = Str::random(40);
        $user = User::create([
            'name' => 'Router Admin',
            'email' => 'router-admin@example.test',
            'password' => 'test-only-password',
        ]);
        AdminApiToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(),
        ]);
        return ['Authorization' => 'Bearer '.$token];
    }

    private function settings(): array
    {
        return [
            'base_url' => 'http://192.168.88.1:8081/rest',
            'username' => 'router-admin',
            'password' => 'test-only-password',
            'verify_tls' => false,
            'hotspot_server' => 'hotspot1',
            'address_pool' => '',
        ];
    }

    public function test_hotspot_login_download_requires_admin_and_reachable_portal_url(): void
    {
        $this->get('/api/admin/router/hotspot-login?portal_url=http%3A%2F%2F10.10.1.254%3A5174')->assertUnauthorized();
        $headers = $this->adminHeaders();
        $this->getJson('/api/admin/router/hotspot-login?portal_url=http%3A%2F%2F127.0.0.1%3A5174', $headers)->assertUnprocessable();

        foreach (['https://other.example', 'http://wifi.95-111-248-145.sslip.io',
            'https://wifi.95-111-248-145.sslip.io.evil.test',
            'https://wifi.95-111-248-145.sslip.io@evil.test',
            'https://wifi.95-111-248-145.sslip.io/?next=https://evil.test'] as $untrusted) {
            $this->getJson('/api/admin/router/hotspot-login?'.http_build_query(['portal_url'=>$untrusted]), $headers)
                ->assertUnprocessable()->assertJsonValidationErrors('portal_url');
        }
        $response = $this->get('/api/admin/router/hotspot-login?portal_url=https%3A%2F%2Fwifi.95-111-248-145.sslip.io', $headers)->assertOk();
        $this->assertStringContainsString('filename="login.html"', $response->headers->get('Content-Disposition'));
        $html = $response->getContent();
        $this->assertSame(file_get_contents(base_path('../router/hotspot/login.html')), $html);
        $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertHotspotRedirectSemantics($html);
        $this->assertStringNotContainsString('__PORTAL_URL__', $response->getContent());
    }

    private function assertHotspotRedirectSemantics(string $html): void
    {
        $mac = 'AA:BB:CC:DD:EE:FF';
        $original = 'https://example.test/path?first=1&second=two words';
        $rendered = str_replace(['$(hostname)', '$(mac-esc)', '$(link-orig-esc)'],
            ['hgd09sryy9d.sn.mynetname.net', rawurlencode($mac), rawurlencode($original)], $html);
        $document = new \DOMDocument();
        $document->loadHTML($rendered);
        $anchor = (new \DOMXPath($document))->query('//a[@id="continue"]')->item(0);
        $this->assertNotNull($anchor, 'The manual continuation link must exist.');
        $this->assertNotSame('', trim($anchor->textContent));
        $scripts = [];
        foreach ($document->getElementsByTagName('script') as $script) $scripts[] = $script->textContent;

        // Execute the actual downloaded scripts, rather than assuming a particular string layout.
        $process = new \Symfony\Component\Process\Process(['node', '-e', <<<'JS'
const vm = require('node:vm');
const fs = require('node:fs');
const input = JSON.parse(fs.readFileSync(0, 'utf8'));
const anchor = { href: input.href };
const redirects = [];
const context = vm.createContext({
    encodeURIComponent,
    window: { location: { replace: url => redirects.push(url) } },
    document: { getElementById: id => id === 'continue' ? anchor : null },
});
for (const script of input.scripts) vm.runInContext(script, context, { timeout: 1000 });
process.stdout.write(JSON.stringify({ redirects, fallback: anchor.href }));
JS
        ]);
        $process->setInput(json_encode(['scripts'=>$scripts,'href'=>$anchor->getAttribute('href')], JSON_THROW_ON_ERROR));
        $process->setTimeout(10);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $result['redirects'], 'The page must automatically redirect once.');
        $this->assertSame($result['redirects'][0], $result['fallback'], 'Manual fallback must use the same captive URL.');
        $parts = parse_url($result['redirects'][0]);
        $this->assertSame('https', $parts['scheme']);
        $this->assertSame('wifi.95-111-248-145.sslip.io', $parts['host']);
        $this->assertSame('/', $parts['path']);
        foreach (['port','user','pass','fragment'] as $key) $this->assertArrayNotHasKey($key, $parts);
        parse_str($parts['query'], $query);
        $this->assertSame([
            'mac'=>$mac,
            'link-login-only'=>'https://hgd09sryy9d.sn.mynetname.net/login',
            'link-orig'=>$original,
        ], $query);
    }

    public function test_router_setup_requires_admin_and_never_returns_password(): void
    {
        $this->getJson('/api/admin/router/setup')->assertUnauthorized();

        config()->set('mikrotik.password', 'stored-test-secret');
        $response = $this->getJson('/api/admin/router/setup', $this->adminHeaders())->assertOk();
        $response->assertJsonPath('password_set', true);
        $this->assertArrayNotHasKey('password', $response->json());
        $this->assertStringNotContainsString('stored-test-secret', $response->getContent());
    }

    public function test_internet_setup_requires_admin_before_contacting_router(): void
    {
        Http::fake();
        $this->getJson('/api/admin/router/uplink')->assertUnauthorized();
        $this->postJson('/api/admin/router/uplink', ['interface' => 'ether1', 'kind' => 'cable'])->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_router_test_rejects_non_lan_targets_without_http_requests(): void
    {
        Http::fake();
        $settings = $this->settings();
        $settings['base_url'] = 'http://127.0.0.1:8081/rest';

        $this->postJson('/api/admin/router/setup/test', $settings, $this->adminHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('base_url');
        Http::assertNothingSent();
    }

    public function test_successful_router_test_uses_temporary_settings_without_saving(): void
    {
        config()->set('mikrotik.base_url', 'http://10.10.1.1/rest');
        Http::fake([
            '*/rest/system/resource' => Http::response(['version' => '7.20'], 200),
            '*/rest/ip/hotspot' => Http::response([['name' => 'hotspot1']], 200),
            '*/rest/ip/pool' => Http::response([['name' => 'dhcp-pool']], 200),
            '*/rest/system/identity' => Http::response([['name' => 'Customer Router']], 200),
        ]);

        $settings = $this->settings();
        $settings['hotspot_server'] = '';
        $response = $this->postJson('/api/admin/router/setup/test', $settings, $this->adminHeaders())
            ->assertOk()->assertJsonPath('router_name', 'Customer Router')
            ->assertJsonPath('servers.0', 'hotspot1');

        $this->assertSame('http://10.10.1.1/rest', config('mikrotik.base_url'));
        $this->assertStringNotContainsString('test-only-password', $response->getContent());
    }

    public function test_verified_router_settings_are_saved_only_through_allowlisted_keys(): void
    {
        Http::fake([
            '*/rest/system/resource' => Http::response(['version' => '7.20'], 200),
            '*/rest/ip/hotspot' => Http::response([['name' => 'hotspot1']], 200),
            '*/rest/ip/pool' => Http::response([['name' => 'dhcp-pool']], 200),
            '*/rest/system/identity' => Http::response([['name' => 'Customer Router']], 200),
        ]);
        $environment = $this->mock(RouterEnvironment::class);
        $environment->shouldReceive('write')->once()->withArgs(function (array $values) {
            return $values['MIKROTIK_BASE_URL'] === 'http://192.168.88.1:8081/rest'
                && $values['MIKROTIK_PASSWORD'] === 'test-only-password'
                && !isset($values['APP_KEY']);
        });
        Artisan::shouldReceive('call')->with('config:clear')->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('queue:restart')->once()->andReturn(0);

        $response = $this->postJson('/api/admin/router/setup', $this->settings(), $this->adminHeaders())
            ->assertOk();
        $this->assertStringNotContainsString('test-only-password', $response->getContent());
    }

    public function test_single_hotspot_is_selected_automatically_when_connecting(): void
    {
        Http::fake([
            '*/rest/system/resource' => Http::response(['version' => '7.20'], 200),
            '*/rest/ip/hotspot' => Http::response([['name' => 'customer-wifi']], 200),
            '*/rest/ip/pool' => Http::response([], 200),
            '*/rest/system/identity' => Http::response([['name' => 'Customer Router']], 200),
        ]);
        $this->mock(RouterEnvironment::class)->shouldReceive('write')->once()->withArgs(
            fn (array $values) => $values['MIKROTIK_HOTSPOT_SERVER'] === 'customer-wifi'
        );
        Artisan::shouldReceive('call')->with('config:clear')->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('queue:restart')->once()->andReturn(0);
        $settings = $this->settings();
        $settings['hotspot_server'] = '';

        $this->postJson('/api/admin/router/setup', $settings, $this->adminHeaders())
            ->assertOk()->assertJsonPath('message', 'Router connection saved and verified.');
    }

    public function test_multiple_hotspots_require_a_choice_before_saving(): void
    {
        Http::fake([
            '*/rest/system/resource' => Http::response(['version' => '7.20'], 200),
            '*/rest/ip/hotspot' => Http::response([['name' => 'guest'], ['name' => 'staff']], 200),
            '*/rest/ip/pool' => Http::response([], 200),
            '*/rest/system/identity' => Http::response([['name' => 'Customer Router']], 200),
        ]);
        $this->mock(RouterEnvironment::class)->shouldNotReceive('write');
        $settings = $this->settings();
        $settings['hotspot_server'] = '';

        $this->postJson('/api/admin/router/setup', $settings, $this->adminHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('hotspot_server');
    }

    public function test_environment_writer_preserves_other_keys_and_special_characters(): void
    {
        $directory = sys_get_temp_dir().'/rjay-router-env-'.Str::random(10);
        mkdir($directory);
        $originalPath = app()->environmentPath();
        file_put_contents($directory.'/.env', "APP_NAME=Existing\nMIKROTIK_PASSWORD=\"old\"\n");
        try {
            app()->useEnvironmentPath($directory);
            app(RouterEnvironment::class)->write([
                'MIKROTIK_PASSWORD' => 'new#Pass$42"safe',
                'MIKROTIK_BASE_URL' => 'http://192.168.88.1:8081/rest',
            ]);
            $parsed = Dotenv::parse(file_get_contents($directory.'/.env'));
            $this->assertSame('Existing', $parsed['APP_NAME']);
            $this->assertSame('new#Pass$42"safe', $parsed['MIKROTIK_PASSWORD']);
        } finally {
            app()->useEnvironmentPath($originalPath);
            unlink($directory.'/.env');
            rmdir($directory);
        }
    }

    public function test_admin_can_rename_router_without_exposing_router_credentials(): void
    {
        config()->set('mikrotik.base_url', 'http://192.168.88.1:8081/rest');
        config()->set('mikrotik.password', 'test-only-password');
        Http::fake([
            '*/rest/system/identity/set' => Http::response([], 200),
            '*/rest/system/identity' => Http::response([['name' => 'Shop Router']], 200),
        ]);

        $response = $this->postJson('/api/admin/router/identity', ['name' => ' Shop Router '], $this->adminHeaders())
            ->assertOk()->assertJsonPath('router_name', 'Shop Router');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/rest/system/identity/set')
            && $request['name'] === 'Shop Router');
        $this->assertStringNotContainsString('test-only-password', $response->getContent());
    }

    public function test_save_reports_when_settings_were_written_but_cache_reload_failed(): void
    {
        Http::fake([
            '*/rest/system/resource' => Http::response(['version' => '7.20'], 200),
            '*/rest/ip/hotspot' => Http::response([['name' => 'hotspot1']], 200),
            '*/rest/ip/pool' => Http::response([], 200),
            '*/rest/system/identity' => Http::response([['name' => 'Customer Router']], 200),
        ]);
        $this->mock(RouterEnvironment::class)->shouldReceive('write')->once();
        Artisan::shouldReceive('call')->with('config:clear')->once()->andReturn(1);

        $this->postJson('/api/admin/router/setup', $this->settings(), $this->adminHeaders())
            ->assertStatus(202)->assertJsonPath('message', 'Router settings were saved, but Laravel must be restarted to use them.');
    }
}
