<?php

namespace Tests\Feature;

use App\Models\AdminApiToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvancedAccessTest extends TestCase
{
    use RefreshDatabase;

    private function headers(bool $allowed = true): array
    {
        $user = User::create([
            'name' => 'Admin', 'email' => Str::random(8).'@example.test', 'password' => 'test-password',
        ]);
        $user->access_advanced_network_tools = $allowed;
        $user->save();
        $token = Str::random(40);
        AdminApiToken::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token)]);
        return ['Authorization' => 'Bearer '.$token, 'X-Client-Platform' => 'Linux'];
    }

    public function test_access_requires_authentication_and_permission(): void
    {
        Http::fake();
        $this->getJson('/api/admin/router/advanced/access')->assertUnauthorized();
        $this->getJson('/api/admin/router/advanced/access', $this->headers(false))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_services_use_live_ports_and_never_return_secret_fields(): void
    {
        config()->set('mikrotik.base_url', 'https://10.10.1.1:8443/rest');
        config()->set('mikrotik.username', 'router-api');
        config()->set('mikrotik.password', 'private-test-password');
        Http::fake([
            '*/rest/system/identity' => Http::response([['name' => 'Main Router']], 200),
            '*/rest/ip/service' => Http::response([
                ['name' => 'winbox', 'port' => '9000', 'disabled' => 'false', 'address' => '10.10.1.0/24', 'password' => 'must-not-leak'],
                ['name' => 'www-ssl', 'port' => '8444', 'disabled' => 'false'],
                ['name' => 'ssh', 'port' => '22', 'disabled' => 'true'],
            ], 200),
        ]);

        $response = $this->getJson('/api/admin/router/advanced/access', $this->headers())->assertOk()
            ->assertJsonPath('services.winbox.port', 9000)
            ->assertJsonPath('services.ssh.enabled', false)
            ->assertJsonPath('webfig_url', 'https://10.10.1.1:8444');
        $this->assertStringNotContainsString('private-test-password', $response->getContent());
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $this->assertDatabaseHas('advanced_router_audit_events', ['action' => 'ADVANCED_TOOLS_OPENED', 'client_platform' => 'Linux']);
    }

    public function test_disabled_https_has_no_webfig_url(): void
    {
        config()->set('mikrotik.password', 'test-password');
        Http::fake([
            '*/rest/system/identity' => Http::response([['name' => 'Router']], 200),
            '*/rest/ip/service' => Http::response([['name' => 'www-ssl', 'port' => '443', 'disabled' => 'true']], 200),
        ]);
        $this->getJson('/api/admin/router/advanced/access', $this->headers())->assertOk()
            ->assertJsonPath('webfig_url', null)->assertJsonPath('services.www-ssl.enabled', false);
    }
}
