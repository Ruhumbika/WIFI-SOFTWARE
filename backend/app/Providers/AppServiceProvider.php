<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (['voucher-lookup'=>5,'voucher-verify'=>5,'voucher-redeem'=>10,'voucher-claim'=>5,'voucher-read'=>30,'voucher-issue'=>5,'voucher-prepare'=>15,'voucher-transfer'=>2,'voucher-compromise'=>2,'voucher-admin'=>5] as $name=>$attempts) {
            \Illuminate\Support\Facades\RateLimiter::for($name, fn (\Illuminate\Http\Request $request) =>
                \Illuminate\Cache\RateLimiting\Limit::perMinute($attempts)->by($name.'|'.($request->user()?->id ?? $request->ip())));
        }
    }
}
