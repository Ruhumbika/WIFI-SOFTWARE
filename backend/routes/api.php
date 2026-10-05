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
    Route::get('/businesses', [\App\Http\Controllers\BusinessController::class, 'index'])->middleware('permission:businesses.view');
    Route::post('/businesses', [\App\Http\Controllers\BusinessController::class, 'store'])->middleware('permission:businesses.manage');
    Route::get('/businesses/{business:uuid}', [\App\Http\Controllers\BusinessController::class, 'show'])->middleware('permission:businesses.view');
    Route::put('/businesses/{business:uuid}', [\App\Http\Controllers\BusinessController::class, 'update'])->middleware('permission:businesses.manage');
    Route::post('/businesses/{business:uuid}/gateways', [\App\Http\Controllers\BusinessController::class, 'storeGateway'])->middleware('permission:businesses.manage');
    Route::put('/businesses/{business:uuid}/gateways/{gateway:uuid}', [\App\Http\Controllers\BusinessController::class, 'updateGateway'])->withoutScopedBindings()->middleware('permission:businesses.manage');
    Route::get('/router/advanced/permission', [AdvancedAccessController::class, 'permission'])->middleware('permission:router.advanced');
    Route::get('/router/advanced/access', [AdvancedAccessController::class, 'access'])->middleware('throttle:20,1')->middleware('permission:router.advanced');
    Route::post('/router/advanced/events', [AdvancedAccessController::class, 'event'])->middleware('throttle:30,1')->middleware('permission:router.advanced');
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/permissions', [\App\Http\Controllers\AdminUserController::class, 'catalog'])->middleware('permission:users.view');
    Route::get('/users', [\App\Http\Controllers\AdminUserController::class, 'index'])->middleware('permission:users.view');
    Route::get('/users/{user}', [\App\Http\Controllers\AdminUserController::class, 'show'])->middleware('permission:users.view');
    Route::middleware(['permission:users.manage', 'permission:super'])->group(function () {
        Route::post('/users', [\App\Http\Controllers\AdminUserController::class, 'store']);
        Route::put('/users/{user}', [\App\Http\Controllers\AdminUserController::class, 'update']);
        Route::patch('/users/{user}/status', [\App\Http\Controllers\AdminUserController::class, 'update']);
        Route::put('/users/{user}/permissions', [\App\Http\Controllers\AdminUserController::class, 'update']);
        Route::put('/users/{user}/password', [\App\Http\Controllers\AdminUserController::class, 'password']);
    });
    Route::get('/dashboard/analytics', [AdminController::class, 'analytics'])->middleware('permission:dashboard.view');
    Route::get('/support', [\App\Http\Controllers\SupportRequestController::class,'index'])->middleware('permission:support.view');
    Route::get('/support/{supportRequest:uuid}/history', [\App\Http\Controllers\SupportRequestController::class,'history'])->middleware('permission:support.view');
    Route::patch('/support/{supportRequest:uuid}', [\App\Http\Controllers\SupportRequestController::class,'update'])->middleware('permission:support.manage');
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->middleware('permission:dashboard.view');
    Route::get('/logs', [AdminController::class, 'logs'])->middleware('permission:logs.view');
    Route::get('/router/health', [AdminController::class, 'routerHealth'])->middleware('permission:router.view');
    Route::get('/router/diagnostics', [AdminController::class, 'routerDiagnostics'])->middleware('permission:router.view');
    Route::post('/router/sync', [AdminController::class, 'routerSync'])->middleware('permission:router.manage');
    Route::get('/router/setup', [RouterSetupController::class, 'show'])->middleware('permission:router.view');
    Route::get('/router/customer-wifi', [RouterSetupController::class, 'customerWifi'])->middleware('permission:router.view');
    Route::patch('/router/customer-wifi', [RouterSetupController::class, 'renameCustomerWifi'])->middleware('throttle:3,1')->middleware('permission:router.manage');
    Route::get('/router/naming', [RouterSetupController::class, 'naming'])->middleware('permission:router.view');
    Route::put('/router/naming', [RouterSetupController::class, 'saveNaming'])->middleware('throttle:3,1')->middleware('permission:router.manage');
    Route::get('/router/uplink', [RouterSetupController::class, 'uplinkStatus'])->middleware('permission:router.view');
    Route::post('/router/uplink', [RouterSetupController::class, 'configureUplink'])->middleware('throttle:3,1')->middleware('permission:router.manage');
    Route::post('/router/setup/test', [RouterSetupController::class, 'test'])->middleware('throttle:6,1')->middleware('permission:router.manage');
    Route::post('/router/setup', [RouterSetupController::class, 'save'])->middleware('throttle:6,1')->middleware('permission:router.manage');
    Route::get('/router/hotspot-login', [RouterSetupController::class, 'downloadHotspotLogin'])->middleware('permission:router.view');
    Route::post('/router/profiles/prepare', [RouterSetupController::class, 'prepareProfiles'])->middleware('throttle:3,1')->middleware('permission:router.manage');
    Route::post('/router/identity', [RouterSetupController::class, 'rename'])->middleware('throttle:6,1')->middleware('permission:router.manage');
    Route::get('/plans', [AdminController::class, 'plans'])->middleware('permission:plans.view');
    Route::post('/plans', [AdminController::class, 'storePlan'])->middleware('permission:plans.manage');
    Route::put('/plans/{plan}', [AdminController::class, 'updatePlan'])->middleware('permission:plans.manage');
    Route::get('/orders', [AdminController::class, 'orders'])->middleware('permission:payments.view');
    Route::get('/payments', [AdminController::class, 'payments'])->middleware('permission:payments.view');
    Route::get('/vouchers', [AdminController::class, 'vouchers'])->middleware('permission:vouchers.view');
    Route::get('/vouchers/{voucher}', [AdminController::class, 'showVoucher'])->middleware('permission:vouchers.view');
    Route::post('/vouchers/generate', [AdminController::class, 'generateVouchers'])->middleware('permission:vouchers.manage');
    Route::post('/vouchers/{voucher}/retry', [AdminController::class, 'retryVoucher'])->middleware('permission:vouchers.manage');
    Route::post('/vouchers/{voucher}/disable', [AdminController::class, 'disableVoucher'])->middleware('permission:vouchers.manage');
    Route::get('/vouchers/{voucher}/device-events', [AdminController::class, 'voucherEvents'])->middleware('permission:vouchers.view');
    Route::post('/vouchers/{voucher}/release-device', [AdminController::class, 'releaseDevice'])->middleware('throttle:voucher-admin')->middleware('permission:vouchers.manage');
    Route::post('/vouchers/{voucher}/transfer/approve', [AdminController::class, 'approveTransfer'])->middleware('throttle:voucher-admin')->middleware('permission:vouchers.manage');
    Route::post('/vouchers/{voucher}/transfer/reject', [AdminController::class, 'rejectTransfer'])->middleware('throttle:voucher-admin')->middleware('permission:vouchers.manage');
    Route::post('/vouchers/{voucher}/rotate-credentials', [AdminController::class, 'rotateCredentials'])->middleware('throttle:voucher-admin')->middleware('permission:vouchers.manage');
    Route::post('/vouchers/{voucher}/recovery-pin', [AdminController::class, 'issueRecovery'])->middleware('throttle:voucher-admin')->middleware('permission:vouchers.manage');
    Route::get('/sessions', [AdminController::class, 'sessions'])->middleware('permission:sessions.view');
    Route::post('/sessions/{session}/disconnect', [AdminController::class, 'disconnectSession'])->middleware('permission:sessions.disconnect');
});
