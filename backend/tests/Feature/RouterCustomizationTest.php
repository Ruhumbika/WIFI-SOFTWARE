<?php

namespace Tests\Feature;

use App\Models\AdminApiToken;
use App\Models\Plan;
use App\Models\User;
use App\Services\RouterEnvironment;
use App\Services\CustomerWifiService;
use App\Services\VoucherProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RouterCustomizationTest extends TestCase
{
    use RefreshDatabase;

    private function headers(bool $allowed = true): array
    {
        $user = User::create(['name' => 'Admin', 'email' => Str::random(8).'@example.test', 'password' => 'test-only']);
        $user->access_advanced_network_tools = $allowed;
        $user->save();
        $plain = Str::random(40);
        AdminApiToken::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $plain)]);
        return ['Authorization' => 'Bearer '.$plain];
    }

    public function test_customer_wifi_and_naming_require_permission(): void
    {
        Http::fake();
        $this->getJson('/api/admin/router/customer-wifi')->assertUnauthorized();
        $this->getJson('/api/admin/router/naming', $this->headers(false))->assertForbidden();
        $this->patchJson('/api/admin/router/customer-wifi', ['interface' => 'wlan1', 'driver' => 'legacy', 'ssid' => 'New'], $this->headers(false))->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_naming_validation_and_save_use_only_allowlisted_environment_keys(): void
    {
        $headers = $this->headers();
        $this->getJson('/api/admin/router/naming', $headers)->assertOk()
            ->assertJsonStructure(['profile_prefix', 'voucher_prefix']);
        $this->putJson('/api/admin/router/naming', ['profile_prefix' => 'bad space', 'voucher_prefix' => 'SHOP-'], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors('profile_prefix');

        $this->mock(RouterEnvironment::class)->shouldReceive('write')->once()->with([
            'MIKROTIK_PROFILE_PREFIX' => 'SHOP_', 'MIKROTIK_VOUCHER_PREFIX' => 'SHOP-',
        ]);
        Artisan::shouldReceive('call')->with('config:clear')->once()->andReturn(0);
        Artisan::shouldReceive('call')->with('queue:restart')->once()->andReturn(0);
        $this->putJson('/api/admin/router/naming', ['profile_prefix' => 'SHOP_', 'voucher_prefix' => 'SHOP-'], $headers)
            ->assertOk()->assertJsonPath('voucher_prefix', 'SHOP-');
    }

    public function test_wifi_rename_validates_32_byte_name_before_router_write(): void
    {
        $headers = $this->headers();
        $this->mock(CustomerWifiService::class)->shouldNotReceive('rename');
        $this->patchJson('/api/admin/router/customer-wifi', [
            'interface' => 'wlan1', 'driver' => 'legacy', 'ssid' => str_repeat('é', 20),
        ], $headers)->assertUnprocessable()->assertJsonValidationErrors('ssid');
    }

    public function test_new_prefixes_apply_only_to_new_vouchers_and_profiles(): void
    {
        $old = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Old', 'code' => 'OLD', 'price' => 500,
            'currency' => 'TZS', 'duration_seconds' => 3600, 'rate_limit' => '2M/2M',
            'mikrotik_profile_name' => 'RJAY_OLD', 'active' => true,
        ]);
        config()->set('mikrotik.profile_prefix', 'SHOP_');
        config()->set('mikrotik.voucher_prefix', 'SHOP-');
        $headers = $this->headers();
        $this->putJson('/api/admin/plans/'.$old->id, [
            'name' => 'Old updated', 'code' => 'OLD', 'price' => 600, 'currency' => 'TZS',
            'duration_seconds' => 3600, 'rate_limit' => '2M/2M', 'active' => true,
        ], $headers)->assertOk();
        $this->assertSame('RJAY_OLD', $old->fresh()->mikrotik_profile_name);

        $new = $this->postJson('/api/admin/plans', [
            'name' => 'New', 'code' => 'NEW', 'price' => 500, 'currency' => 'TZS',
            'duration_seconds' => 3600, 'rate_limit' => '2M/2M', 'active' => true,
        ], $headers)->assertCreated()->json();
        $this->assertSame('SHOP_NEW', $new['mikrotik_profile_name']);

        $voucher = app(VoucherProvisioner::class)->createVoucher(['plan_id' => $old->id]);
        $this->assertStringStartsWith('SHOP-', $voucher->code);
    }
}
