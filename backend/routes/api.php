<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdvancedAccessController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\RouterSetupController;
use App\Http\Controllers\SnippeWebhookController;
use App\Http\Controllers\ClickPesaWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);

Route::prefix('public')->group(function () {
    Route::post('/support', [\App\Http\Controllers\SupportRequestController::class,'store'])->middleware('throttle:support-create');
    Route::post('/orders/{uuid}/support', [\App\Http\Controllers\SupportRequestController::class,'orderStore'])->middleware('throttle:support-create');
    Route::get('/plans', [PublicPortalController::class, 'plans']);
    Route::post('/orders', [PublicPortalController::class, 'createOrder']);
    Route::post('/orders/{uuid}/pay', [PublicPortalController::class, 'pay']);
    Route::post('/orders/{uuid}/resend-push', [PublicPortalController::class, 'resendPush'])->middleware('throttle:6,1');
    Route::get('/orders/{uuid}', [PublicPortalController::class, 'showOrder']);
    Route::get('/orders/{uuid}/connection', [PublicPortalController::class, 'connectionStatus']);
    Route::post('/orders/{uuid}/prepare-connection', [PublicPortalController::class, 'prepareConnection']);
});

Route::prefix('public/vouchers')->controller(\App\Http\Controllers\PublicVoucherController::class)->group(function () {
    Route::post('/{voucher:uuid}/support', [\App\Http\Controllers\SupportRequestController::class,'voucherStore'])->middleware('throttle:support-create');
    Route::post('/redeem','redeem')->middleware('throttle:voucher-redeem');
    Route::post('/recovery/lookup','lookup')->middleware('throttle:voucher-lookup');
    Route::post('/recovery/verify','verify')->middleware('throttle:voucher-verify');
    Route::post('/claim','claim')->middleware('throttle:voucher-claim');
    Route::get('/mine','mine')->middleware('throttle:voucher-read');
    Route::get('/{voucher:uuid}','show')->middleware('throttle:voucher-read');
    Route::post('/{voucher:uuid}/recovery-pin','issue')->middleware('throttle:voucher-issue');
    Route::post('/{voucher:uuid}/prepare-connection','prepare')->middleware('throttle:voucher-prepare');
    Route::get('/{voucher:uuid}/connection','connection')->middleware('throttle:voucher-read');
    Route::post('/{voucher:uuid}/device-transfer-request','transfer')->middleware('throttle:voucher-transfer');
    Route::post('/{voucher:uuid}/report-compromised','compromise')->middleware('throttle:voucher-compromise');
});

Route::post('/webhooks/snippe/{gateway:uuid}', SnippeWebhookController::class);
Route::post('/webhooks/snippe', [SnippeWebhookController::class, 'legacy']);
Route::post('/webhooks/clickpesa', ClickPesaWebhookController::class);

Route::middleware('admin.token')->prefix('admin')->group(function () {
    Route::get('/businesses', [\App\Http\Controllers\BusinessController::class, 'index']);
    Route::post('/businesses', [\App\Http\Controllers\BusinessController::class, 'store']);
    Route::get('/businesses/{business:uuid}', [\App\Http\Controllers\BusinessController::class, 'show']);
    Route::put('/businesses/{business:uuid}', [\App\Http\Controllers\BusinessController::class, 'update']);
    Route::post('/businesses/{business:uuid}/gateways', [\App\Http\Controllers\BusinessController::class, 'storeGateway']);
    Route::put('/businesses/{business:uuid}/gateways/{gateway:uuid}', [\App\Http\Controllers\BusinessController::class, 'updateGateway'])->withoutScopedBindings();
    Route::get('/router/advanced/permission', [AdvancedAccessController::class, 'permission']);
    Route::get('/router/advanced/access', [AdvancedAccessController::class, 'access'])->middleware('throttle:20,1');
    Route::post('/router/advanced/events', [AdvancedAccessController::class, 'event'])->middleware('throttle:30,1');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dashboard/analytics', [AdminController::class, 'analytics']);
    Route::get('/support', [\App\Http\Controllers\SupportRequestController::class,'index']);
    Route::get('/support/{supportRequest:uuid}/history', [\App\Http\Controllers\SupportRequestController::class,'history']);
    Route::patch('/support/{supportRequest:uuid}', [\App\Http\Controllers\SupportRequestController::class,'update']);
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/logs', [AdminController::class, 'logs']);
    Route::get('/router/health', [AdminController::class, 'routerHealth']);
    Route::get('/router/diagnostics', [AdminController::class, 'routerDiagnostics']);
    Route::post('/router/sync', [AdminController::class, 'routerSync']);
    Route::get('/router/setup', [RouterSetupController::class, 'show']);
    Route::get('/router/customer-wifi', [RouterSetupController::class, 'customerWifi']);
    Route::patch('/router/customer-wifi', [RouterSetupController::class, 'renameCustomerWifi'])->middleware('throttle:3,1');
    Route::get('/router/naming', [RouterSetupController::class, 'naming']);
    Route::put('/router/naming', [RouterSetupController::class, 'saveNaming'])->middleware('throttle:3,1');
    Route::get('/router/uplink', [RouterSetupController::class, 'uplinkStatus']);
    Route::post('/router/uplink', [RouterSetupController::class, 'configureUplink'])->middleware('throttle:3,1');
    Route::post('/router/setup/test', [RouterSetupController::class, 'test'])->middleware('throttle:6,1');
    Route::post('/router/setup', [RouterSetupController::class, 'save'])->middleware('throttle:6,1');
    Route::get('/router/hotspot-login', [RouterSetupController::class, 'downloadHotspotLogin']);
    Route::post('/router/profiles/prepare', [RouterSetupController::class, 'prepareProfiles'])->middleware('throttle:3,1');
    Route::post('/router/identity', [RouterSetupController::class, 'rename'])->middleware('throttle:6,1');
    Route::get('/plans', [AdminController::class, 'plans']);
    Route::post('/plans', [AdminController::class, 'storePlan']);
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan']);
    Route::get('/orders', [AdminController::class, 'orders']);
    Route::get('/payments', [AdminController::class, 'payments']);
    Route::get('/vouchers', [AdminController::class, 'vouchers']);
    Route::get('/vouchers/{voucher}', [AdminController::class, 'showVoucher']);
    Route::post('/vouchers/generate', [AdminController::class, 'generateVouchers']);
    Route::post('/vouchers/{voucher}/retry', [AdminController::class, 'retryVoucher']);
    Route::post('/vouchers/{voucher}/disable', [AdminController::class, 'disableVoucher']);
    Route::get('/vouchers/{voucher}/device-events', [AdminController::class, 'voucherEvents']);
    Route::post('/vouchers/{voucher}/release-device', [AdminController::class, 'releaseDevice'])->middleware('throttle:voucher-admin');
    Route::post('/vouchers/{voucher}/transfer/approve', [AdminController::class, 'approveTransfer'])->middleware('throttle:voucher-admin');
    Route::post('/vouchers/{voucher}/transfer/reject', [AdminController::class, 'rejectTransfer'])->middleware('throttle:voucher-admin');
    Route::post('/vouchers/{voucher}/rotate-credentials', [AdminController::class, 'rotateCredentials'])->middleware('throttle:voucher-admin');
    Route::post('/vouchers/{voucher}/recovery-pin', [AdminController::class, 'issueRecovery'])->middleware('throttle:voucher-admin');
    Route::get('/sessions', [AdminController::class, 'sessions']);
    Route::post('/sessions/{session}/disconnect', [AdminController::class, 'disconnectSession']);
});
