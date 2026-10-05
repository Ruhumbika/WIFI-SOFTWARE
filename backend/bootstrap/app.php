<?php

use App\Http\Middleware\AdminTokenAuth;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToPriorityList([\Illuminate\Routing\Middleware\ThrottleRequests::class, \Illuminate\Routing\Middleware\SubstituteBindings::class], \App\Http\Middleware\RequireAdminPermission::class);
        $middleware->prependToPriorityList(\App\Http\Middleware\RequireAdminPermission::class, AdminTokenAuth::class);
        $middleware->alias([
            'admin.token' => AdminTokenAuth::class,
            'permission' => \App\Http\Middleware\RequireAdminPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['pin', 'recovery_pin', 'secret', 'password', 'password_confirmation', 'current_password', 'api_key', 'webhook_secret', 'api_key_encrypted', 'webhook_secret_encrypted']);
    })->create();
