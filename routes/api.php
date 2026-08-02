<?php

declare(strict_types=1);

use App\Http\Controllers\Central\CentralAuthController;
use App\Http\Controllers\Central\ModuleController;
use App\Http\Controllers\Central\TenantController;
use App\Http\Controllers\Central\TenantModuleController;
use App\Http\Responses\ApiResponse;
use App\Modules\Rbac\Http\Controllers\ForgotPasswordController;
use App\Modules\Rbac\Http\Controllers\ResetPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::success(['status' => 'ok'], 'OK'))->middleware('api');

foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->middleware('api')->group(function () {
        Route::get('/', fn () => ApiResponse::success(null, 'Central application.'));

        Route::post('/login', [CentralAuthController::class, 'login'])
            ->middleware('throttle:login');
        Route::post('/logout', [CentralAuthController::class, 'logout'])
            ->middleware(['auth:sanctum', 'throttle:60,1']);
        Route::post('/forgot-password', ForgotPasswordController::class)
            ->middleware('throttle:password-reset');
        Route::post('/reset-password', ResetPasswordController::class)
            ->middleware('throttle:password-reset');

        Route::get('/tenants', [TenantController::class, 'index']);
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::get('/tenant/get-tenant-by-name/{name}', [TenantController::class, 'lookupByName'])
            ->where('name', '[A-Za-z0-9][A-Za-z0-9_-]*');

        Route::get('/modules', [ModuleController::class, 'index']);
        Route::get('/tenants/{tenant}/modules', [TenantModuleController::class, 'show']);
        Route::put('/tenants/{tenant}/modules', [TenantModuleController::class, 'update']);
    });
}
