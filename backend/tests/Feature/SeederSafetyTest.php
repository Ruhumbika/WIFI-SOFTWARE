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
        $this->assertTrue($admin->fresh()->is_super_user);
        $this->assertSame(count(\App\Support\AdminPermissions::KEYS), \App\Models\Permission::count());
    }
    public function test_reseeding_never_promotes_existing_staff_or_resets_permissions(): void
    {
        config()->set('admin.seed_email', 'staff@example.test');
        config()->set('admin.seed_password', 'seed-password');
        $staff = User::create(['name' => 'Staff', 'email' => 'staff@example.test', 'password' => 'staff-password']);
        $staff->permissions()->sync(\App\Models\Permission::where('key', 'payments.view')->pluck('id'));
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);
        $this->assertFalse($staff->fresh()->is_super_user);
        $this->assertTrue(Hash::check('staff-password', $staff->fresh()->password));
        $this->assertSame(['payments.view'], $staff->fresh()->adminAccess()['permissions']);
        $this->assertSame(count(\App\Support\AdminPermissions::KEYS), \App\Models\Permission::count());
    }
}
