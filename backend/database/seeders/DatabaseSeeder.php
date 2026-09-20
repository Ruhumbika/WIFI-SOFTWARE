<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('admin.seed_email');
        $password = config('admin.seed_password');
        if (!$email || !$password) {
            throw new \RuntimeException('ADMIN_EMAIL and ADMIN_PASSWORD must be set before seeding.');
        }
        User::firstOrCreate(['email'=>$email], [
            'name'=>'RJAY Hotspot Admin',
            'password'=>Hash::make($password),
        ]);

        if (Plan::query()->exists()) return;

        $plans = [
            ['name'=>'Chap Chap 1 Hour','code'=>'1H','price'=>500,'duration_seconds'=>3600,'rate_limit'=>'2M/2M'],
            ['name'=>'9 Hours','code'=>'9H','price'=>1000,'duration_seconds'=>32400,'rate_limit'=>'4M/4M'],
            ['name'=>'Boom 1 Day','code'=>'1D','price'=>2000,'duration_seconds'=>86400,'rate_limit'=>'4M/4M'],
            ['name'=>'Boom 3 Days','code'=>'3D','price'=>4000,'duration_seconds'=>259200,'rate_limit'=>'4M/4M'],
            ['name'=>'Boom 7 Days','code'=>'7D','price'=>7000,'duration_seconds'=>604800,'rate_limit'=>'4M/4M'],
        ];
        foreach($plans as $p) {
            Plan::create(array_merge($p,[
                'uuid'=>(string)Str::uuid(), 'currency'=>'TZS','active'=>true,
                'mikrotik_profile_name'=>config('mikrotik.profile_prefix').$p['code'],
            ]));
        }
    }
}
