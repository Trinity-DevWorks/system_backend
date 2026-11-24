<?php

declare(strict_types=1);

use App\Http\Controllers\Central\CentralAuditController;
use App\Http\Controllers\Central\CentralAuthController;
use App\Http\Controllers\Central\CentralMeController;
use App\Http\Controllers\Central\CentralOverviewController;
use App\Http\Controllers\Central\CentralPermissionController;
use App\Http\Controllers\Central\CentralRoleController;
use App\Http\Controllers\Central\CentralRolePermissionController;
use App\Http\Controllers\Central\CentralUserController;
use App\Http\Controllers\Central\ModuleController;
use App\Http\Controllers\Central\PlanController;
use App\Http\Controllers\Central\PlatformBrandingController;
use App\Http\Controllers\Central\TenantController;
use App\Http\Controllers\Central\TenantModuleController;
use App\Http\Responses\ApiResponse;
use App\Modules\Rbac\Http\Controllers\ForgotPasswordController;
use App\Modules\Rbac\Http\Controllers\ResetPasswordController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ApiResponse::success(['status' => 'ok'], 'OK'))->middleware('api');

// Routes are registered once per central domain, so they stay unnamed (no apiResource).
foreach (config('tenancy.central_domains') as $domain) {
    Route::domain($domain)->middleware('api')->group(function () {
        Route::get('/', fn () => ApiResponse::success(null, 'Central application.'));

        Route::post('/login', [CentralAuthController::class, 'login'])
            ->middleware('throttle:login');
        Route::post('/forgot-password', ForgotPasswordController::class)
            ->middleware('throttle:password-reset');
        Route::post('/reset-password', ResetPasswordController::class)
            ->middleware('throttle:password-reset');

        // Public: product name and logo for login pages, titles and favicons on every host.
        Route::get('/branding', [PlatformBrandingController::class, 'show'])
            ->middleware('throttle:tenant-lookup');
        Route::get('/branding/logo', [PlatformBrandingController::class, 'logo'])
            ->middleware('throttle:tenant-lookup');

        // Public: the Next.js proxy resolves the tenant from the host before anyone signs in.
        Route::get('/tenant/get-tenant-by-name/{name}', [TenantController::class, 'lookupByName'])
            ->where('name', '[A-Za-z0-9][A-Za-z0-9_-]*')
            ->middleware('throttle:tenant-lookup');

        Route::middleware(['auth:sanctum', 'ensure.active', 'throttle:60,1'])->group(function () {
            Route::post('/logout', [CentralAuthController::class, 'logout']);
            Route::get('/auth/me', [CentralMeController::class, 'show']);
            Route::put('/auth/me', [CentralMeController::class, 'update']);

            // When adding check.central.permission:<resource>,<action>, add that
            // action to config/central_rbac.php for the same resource.
            Route::get('/overview', CentralOverviewController::class)
                ->middleware('check.central.permission:overview,view');

            Route::get('/tenants', [TenantController::class, 'index'])
                ->middleware('check.central.permission:tenants,view');
            Route::post('/tenants', [TenantController::class, 'store'])
                ->middleware('check.central.permission:tenants,add');
            Route::get('/tenants/{tenant}', [TenantController::class, 'show'])
                ->middleware('check.central.permission:tenants,view');
            Route::put('/tenants/{tenant}', [TenantController::class, 'update'])
                ->middleware('check.central.permission:tenants,edit');
            Route::patch('/tenants/{tenant}/status', [TenantController::class, 'updateStatus'])
                ->middleware('check.central.permission:tenants,edit');
            Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy'])
                ->middleware('check.central.permission:tenants,delete');
            Route::get('/tenants/{tenant}/modules', [TenantModuleController::class, 'show'])
                ->middleware('check.central.permission:tenant_modules,view');
            Route::put('/tenants/{tenant}/subscription', [TenantController::class, 'updateSubscription'])
                ->middleware('check.central.permission:tenants,edit');

            Route::get('/modules', [ModuleController::class, 'index'])
                ->middleware('check.central.permission:modules,view');

            Route::get('/plans', [PlanController::class, 'index'])
                ->middleware('check.central.permission:plans,view');
            Route::post('/plans', [PlanController::class, 'store'])
                ->middleware('check.central.permission:plans,add');
            Route::get('/plans/{plan}', [PlanController::class, 'show'])
                ->middleware('check.central.permission:plans,view');
            Route::put('/plans/{plan}', [PlanController::class, 'update'])
                ->middleware('check.central.permission:plans,edit');
            Route::delete('/plans/{plan}', [PlanController::class, 'destroy'])
                ->middleware('check.central.permission:plans,delete');

            Route::get('/users', [CentralUserController::class, 'index'])
                ->middleware('check.central.permission:users,view');
            Route::post('/users', [CentralUserController::class, 'store'])
                ->middleware('check.central.permission:users,add');
            Route::get('/users/{user}', [CentralUserController::class, 'show'])
                ->middleware('check.central.permission:users,view');
            Route::put('/users/{user}', [CentralUserController::class, 'update'])
                ->middleware('check.central.permission:users,edit');
            Route::delete('/users/{user}', [CentralUserController::class, 'destroy'])
                ->middleware('check.central.permission:users,delete');

            Route::get('/roles', [CentralRoleController::class, 'index'])
                ->middleware('check.central.permission:roles,view');
            Route::post('/roles', [CentralRoleController::class, 'store'])
                ->middleware('check.central.permission:roles,add');
            Route::get('/roles/{role}', [CentralRoleController::class, 'show'])
                ->middleware('check.central.permission:roles,view');
            Route::put('/roles/{role}', [CentralRoleController::class, 'update'])
                ->middleware('check.central.permission:roles,edit');
            Route::delete('/roles/{role}', [CentralRoleController::class, 'destroy'])
                ->middleware('check.central.permission:roles,delete');

            Route::get('/permissions', [CentralPermissionController::class, 'index'])
                ->middleware('check.central.permission:permissions,view');
            Route::get('/permissions/roles', [CentralRolePermissionController::class, 'roles'])
                ->middleware('check.central.permission:permissions,view');
            Route::get('/roles/{role}/permissions', [CentralRolePermissionController::class, 'show'])
                ->middleware('check.central.permission:permissions,view');
            Route::put('/roles/{role}/permissions', [CentralRolePermissionController::class, 'update'])
                ->middleware('check.central.permission:permissions,edit');

            Route::get('/audits', [CentralAuditController::class, 'index'])
                ->middleware('check.central.permission:audits,view');
            Route::get('/audits/export', [CentralAuditController::class, 'export'])
                ->middleware('check.central.permission:audits,export');
            Route::get('/audits/{audit}', [CentralAuditController::class, 'show'])
                ->middleware('check.central.permission:audits,view');

            Route::get('/platform-settings', [PlatformBrandingController::class, 'show'])
                ->middleware('check.central.permission:platform_settings,view');
            Route::put('/platform-settings', [PlatformBrandingController::class, 'update'])
                ->middleware('check.central.permission:platform_settings,edit');
            Route::post('/platform-settings/logo', [PlatformBrandingController::class, 'storeLogo'])
                ->middleware('check.central.permission:platform_settings,edit');
            Route::delete('/platform-settings/logo', [PlatformBrandingController::class, 'destroyLogo'])
                ->middleware('check.central.permission:platform_settings,edit');
        });
    });
}
