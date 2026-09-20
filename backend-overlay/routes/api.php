<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\SnippeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::prefix('public')->group(function () {
    Route::get('/plans', [PublicPortalController::class, 'plans']);
    Route::post('/orders', [PublicPortalController::class, 'createOrder']);
    Route::post('/orders/{uuid}/pay', [PublicPortalController::class, 'pay']);
    Route::get('/orders/{uuid}', [PublicPortalController::class, 'showOrder']);
    Route::post('/orders/{uuid}/mock-complete', [PublicPortalController::class, 'mockComplete']);
});

Route::post('/webhooks/snippe', SnippeWebhookController::class);

Route::middleware('admin.token')->prefix('admin')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/router/health', [AdminController::class, 'routerHealth']);
    Route::get('/plans', [AdminController::class, 'plans']);
    Route::post('/plans', [AdminController::class, 'storePlan']);
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan']);
    Route::get('/orders', [AdminController::class, 'orders']);
    Route::get('/payments', [AdminController::class, 'payments']);
    Route::get('/vouchers', [AdminController::class, 'vouchers']);
    Route::post('/vouchers/generate', [AdminController::class, 'generateVouchers']);
    Route::post('/vouchers/{voucher}/retry', [AdminController::class, 'retryVoucher']);
    Route::get('/sessions', [AdminController::class, 'sessions']);
    Route::post('/sessions/{session}/disconnect', [AdminController::class, 'disconnectSession']);
});
