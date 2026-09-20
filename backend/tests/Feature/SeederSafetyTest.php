<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_reseeding_does_not_reset_existing_admin_or_package_values(): void
    {
        config()->set('admin.seed_email', 'admin@example.test');
        config()->set('admin.seed_password', 'first-test-password');
        config()->set('mikrotik.profile_prefix', 'TEST_');
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $admin->forceFill(['password' => Hash::make('new-test-password')])->save();
        $plan = Plan::where('code', '1H')->firstOrFail();
        $plan->forceFill(['price' => 1234])->save();

        $this->seed(DatabaseSeeder::class);

        $this->assertTrue(Hash::check('new-test-password', $admin->fresh()->password));
        $this->assertSame(1234, $plan->fresh()->price);
        $this->assertSame('TEST_1H', $plan->fresh()->mikrotik_profile_name);
        $this->assertSame(5, Plan::count());
    }
}
