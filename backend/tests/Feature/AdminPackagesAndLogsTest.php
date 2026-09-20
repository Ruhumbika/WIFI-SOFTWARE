<?php

namespace Tests\Feature;

use App\Models\AdminApiToken;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPackagesAndLogsTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $token = Str::random(40);
        $user = User::create(['name' => 'Test Admin', 'email' => 'admin@example.test', 'password' => 'test-only']);
        AdminApiToken::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_used_package_can_be_edited_and_deactivated_without_changing_voucher_terms(): void
    {
        $plan = Plan::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Day', 'code' => 'DAY', 'price' => 2000,
            'currency' => 'TZS', 'duration_seconds' => 86400, 'rate_limit' => '4M/4M',
            'mikrotik_profile_name' => 'RJAY_DAY', 'active' => true,
        ]);
        Voucher::create(['uuid' => (string) Str::uuid(), 'code' => 'RJAY-TEST01', 'secret' => 'test-only', 'plan_id' => $plan->id, 'status' => 'ready']);
        $headers = $this->adminHeaders();
        $payload = ['name' => 'Day Offer', 'code' => 'DAY', 'price' => 2500, 'currency' => 'TZS', 'duration_seconds' => 86400, 'rate_limit' => '4M/4M', 'active' => false, 'recommended' => true];

        $this->getJson('/api/admin/plans', $headers)->assertOk()->assertJsonPath('0.vouchers_exists', true);
        $this->putJson('/api/admin/plans/'.$plan->id, $payload, $headers)->assertOk()->assertJsonPath('active', false)->assertJsonPath('recommended', true);
        $this->assertSame('Day Offer', $plan->fresh()->name);
        $this->assertSame('RJAY_DAY', $plan->fresh()->mikrotik_profile_name);
        $this->getJson('/api/public/plans')->assertOk()->assertExactJson([]);

        $this->putJson('/api/admin/plans/'.$plan->id, array_merge($payload, ['duration_seconds' => 3600]), $headers)->assertUnprocessable();
        $this->assertSame(86400, $plan->fresh()->duration_seconds);
    }

    public function test_admin_log_view_excludes_raw_messages_and_requires_authentication(): void
    {
        $this->getJson('/api/admin/logs')->assertUnauthorized();
        $original = app()->storagePath();
        $directory = sys_get_temp_dir().'/rjay-logs-'.Str::random(10);
        mkdir($directory.'/logs', 0700, true);
        file_put_contents($directory.'/logs/laravel.log', "[2026-09-20 09:59:00] testing.WARNING: Routine warning\n[2026-09-20 10:00:00] testing.ERROR: Read system/resource failed: HTTP 401. password=private-test-value\n");
        try {
            app()->useStoragePath($directory);
            $response = $this->getJson('/api/admin/logs', $this->adminHeaders())->assertOk();
            $response->assertJsonPath('entries.0.message', 'Router REST read system/resource failed (HTTP 401).');
            $this->assertCount(1, $response->json('entries'));
            $this->assertStringNotContainsString('private-test-value', $response->getContent());
        } finally {
            app()->useStoragePath($original);
            unlink($directory.'/logs/laravel.log');
            rmdir($directory.'/logs');
            rmdir($directory);
        }
    }
}
