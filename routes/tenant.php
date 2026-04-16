<?php

declare(strict_types=1);

use App\Modules\Category\Http\Controllers\CategoryController;
use App\Modules\Currency\Http\Controllers\CurrencyController;
use App\Modules\Customer\Http\Controllers\CustomerAddressController;
use App\Modules\Customer\Http\Controllers\CustomerAttachmentController;
use App\Modules\Customer\Http\Controllers\CustomerContactController;
use App\Modules\Customer\Http\Controllers\CustomerController;
use App\Modules\Customer\Http\Controllers\CustomerGroupController;
use App\Modules\Customer\Http\Controllers\CustomerLedgerController;
use App\Modules\Inventory\Item\Http\Controllers\ItemBarcodeController;
use App\Modules\Inventory\Item\Http\Controllers\ItemController;
use App\Modules\Inventory\Item\Http\Controllers\ItemUomController;
use App\Modules\Inventory\UnitGroup\Http\Controllers\UnitGroupController;
use App\Modules\Inventory\UnitOfMeasurement\Http\Controllers\UnitOfMeasurementController;
use App\Modules\Rbac\Http\Controllers\LoginController;
use App\Modules\Rbac\Http\Controllers\PermissionController;
use App\Modules\Rbac\Http\Controllers\RoleController;
use App\Modules\Rbac\Http\Controllers\UserController;
use App\Modules\Rbac\Http\Controllers\UserRoleController;
use App\Modules\SubCategory\Http\Controllers\SubCategoryController;
use App\Modules\Supplier\Http\Controllers\SupplierAddressController;
use App\Modules\Supplier\Http\Controllers\SupplierAttachmentController;
use App\Modules\Supplier\Http\Controllers\SupplierContactController;
use App\Modules\Supplier\Http\Controllers\SupplierController;
use App\Modules\Supplier\Http\Controllers\SupplierGroupController;
use App\Modules\Supplier\Http\Controllers\SupplierLedgerController;
use App\Modules\VatGroup\Http\Controllers\VatGroupController;
use App\Modules\Warehouse\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here you can register the tenant routes for your application.
| These routes are loaded by the TenantRouteServiceProvider.
|
| Feel free to customize them however you want. Good luck!
|
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    // Public Routes
    Route::get('/', function () {
        return response()->json([
            'tenant_id' => tenant('id'),
            'message' => 'Tenant application.',
        ]);
    });

    // Login Route (rate limit: see config/security.php + LOGIN_RATE_LIMIT_PER_MINUTE)
    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:login');

    // Protected Routes
    Route::middleware(['auth:sanctum'])->group(function () {
        // Permission Management Routes
        Route::get('permissions', [PermissionController::class, 'index'])
            ->middleware('check.permission:permissions,view');

        Route::apiResource('roles', RoleController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:roles,view'])
            ->middlewareFor(['store'], ['check.permission:roles,add'])
            ->middlewareFor(['update'], ['check.permission:roles,edit'])
            ->middlewareFor(['destroy'], ['check.permission:roles,delete']);

        // User Management Routes
        Route::get('users', [UserController::class, 'index'])
            ->middleware('check.permission:users,view');
        Route::patch('users/{user}/role', [UserRoleController::class, 'update'])
            ->middleware('check.permission:users,edit');

        // Category Management Routes
        Route::apiResource('categories', CategoryController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:categories,view'])
            ->middlewareFor(['store'], ['check.permission:categories,add'])
            ->middlewareFor(['update'], ['check.permission:categories,edit'])
            ->middlewareFor(['destroy'], ['check.permission:categories,delete']);

        // Sub Category Management Routes
        Route::apiResource('sub-categories', SubCategoryController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:sub_categories,view'])
            ->middlewareFor(['store'], ['check.permission:sub_categories,add'])
            ->middlewareFor(['update'], ['check.permission:sub_categories,edit'])
            ->middlewareFor(['destroy'], ['check.permission:sub_categories,delete']);

        // Vat Group Management Routes
        Route::apiResource('vat-groups', VatGroupController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:vat_groups,view'])
            ->middlewareFor(['store'], ['check.permission:vat_groups,add'])
            ->middlewareFor(['update'], ['check.permission:vat_groups,edit'])
            ->middlewareFor(['destroy'], ['check.permission:vat_groups,delete']);

        // Currency Management Routes
        Route::get('currencies/{currency}/rate-history', [CurrencyController::class, 'rateHistory'])
            ->middleware('check.permission:currencies,view');
        Route::apiResource('currencies', CurrencyController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:currencies,view'])
            ->middlewareFor(['store'], ['check.permission:currencies,add'])
            ->middlewareFor(['update'], ['check.permission:currencies,edit'])
            ->middlewareFor(['destroy'], ['check.permission:currencies,delete']);

        // Warehouse Management Routes
        Route::apiResource('warehouses', WarehouseController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:warehouses,view'])
            ->middlewareFor(['store'], ['check.permission:warehouses,add'])
            ->middlewareFor(['update'], ['check.permission:warehouses,edit'])
            ->middlewareFor(['destroy'], ['check.permission:warehouses,delete']);

        // Unit Group Management Routes
        Route::get('unit-groups/{unit_group}/units', [UnitGroupController::class, 'units'])
            ->middleware('check.permission:unit_groups,view');

        // Unit Group Management Routes
        Route::apiResource('unit-groups', UnitGroupController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:unit_groups,view'])
            ->middlewareFor(['store'], ['check.permission:unit_groups,add'])
            ->middlewareFor(['update'], ['check.permission:unit_groups,edit'])
            ->middlewareFor(['destroy'], ['check.permission:unit_groups,delete']);

        // Unit of Measurement Management Routes
        Route::apiResource('unit-of-measurements', UnitOfMeasurementController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:unit_of_measurements,view'])
            ->middlewareFor(['store'], ['check.permission:unit_of_measurements,add'])
            ->middlewareFor(['update'], ['check.permission:unit_of_measurements,edit'])
            ->middlewareFor(['destroy'], ['check.permission:unit_of_measurements,delete']);

        // Item Unit of Measurement Management Routes
        Route::get('items/{item}/unit-of-measurements', [ItemUomController::class, 'index'])
            ->middleware('check.permission:items,view');
        Route::post('items/{item}/unit-of-measurements', [ItemUomController::class, 'store'])
            ->middleware('check.permission:items,edit');
        Route::put('items/{item}/unit-of-measurements/{unit_of_measurement}', [ItemUomController::class, 'update'])
            ->middleware('check.permission:items,edit');
        Route::delete('items/{item}/unit-of-measurements/{unit_of_measurement}', [ItemUomController::class, 'destroy'])
            ->middleware('check.permission:items,edit');
        Route::post('items/{item}/barcodes', [ItemBarcodeController::class, 'store'])
            ->middleware('check.permission:items,edit');

        // Item Management Routes
        Route::apiResource('items', ItemController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:items,view'])
            ->middlewareFor(['store'], ['check.permission:items,add'])
            ->middlewareFor(['update'], ['check.permission:items,edit'])
            ->middlewareFor(['destroy'], ['check.permission:items,delete']);

        // Customer Group Routes
        Route::apiResource('customer-groups', CustomerGroupController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:customer_groups,view'])
            ->middlewareFor(['store'], ['check.permission:customer_groups,add'])
            ->middlewareFor(['update'], ['check.permission:customer_groups,edit'])
            ->middlewareFor(['destroy'], ['check.permission:customer_groups,delete']);

        // Customer Routes (nested & ledger before apiResource)
        Route::get('customers/{customer}/balance', [CustomerLedgerController::class, 'balance'])
            ->middleware('check.permission:customers,view');
        Route::get('customers/{customer}/ledger-entries', [CustomerLedgerController::class, 'index'])
            ->middleware('check.permission:customers,view');

        Route::apiResource('customers.addresses', CustomerAddressController::class)
            ->scoped()
            ->middlewareFor(['index', 'show'], ['check.permission:customers,view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['check.permission:customers,edit']);

        Route::apiResource('customers.contacts', CustomerContactController::class)
            ->scoped()
            ->middlewareFor(['index', 'show'], ['check.permission:customers,view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['check.permission:customers,edit']);

        Route::get('customers/{customer}/attachments/{attachment}/download', [CustomerAttachmentController::class, 'download'])
            ->middleware('check.permission:customers,view')
            ->name('customers.attachments.download');

        Route::apiResource('customers.attachments', CustomerAttachmentController::class)
            ->only(['index', 'store', 'show', 'destroy'])
            ->middlewareFor(['index', 'show'], ['check.permission:customers,view'])
            ->middlewareFor(['store', 'destroy'], ['check.permission:customers,edit']);

        Route::apiResource('customers', CustomerController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:customers,view'])
            ->middlewareFor(['store'], ['check.permission:customers,add'])
            ->middlewareFor(['update'], ['check.permission:customers,edit'])
            ->middlewareFor(['destroy'], ['check.permission:customers,delete']);

        // Supplier Group Routes
        Route::apiResource('supplier-groups', SupplierGroupController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:supplier_groups,view'])
            ->middlewareFor(['store'], ['check.permission:supplier_groups,add'])
            ->middlewareFor(['update'], ['check.permission:supplier_groups,edit'])
            ->middlewareFor(['destroy'], ['check.permission:supplier_groups,delete']);

        // Supplier Routes (nested & ledger before apiResource)
        Route::get('suppliers/{supplier}/balance', [SupplierLedgerController::class, 'balance'])
            ->middleware('check.permission:suppliers,view');
        Route::get('suppliers/{supplier}/ledger-entries', [SupplierLedgerController::class, 'index'])
            ->middleware('check.permission:suppliers,view');

        Route::apiResource('suppliers.addresses', SupplierAddressController::class)
            ->scoped()
            ->middlewareFor(['index', 'show'], ['check.permission:suppliers,view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['check.permission:suppliers,edit']);

        Route::apiResource('suppliers.contacts', SupplierContactController::class)
            ->scoped()
            ->middlewareFor(['index', 'show'], ['check.permission:suppliers,view'])
            ->middlewareFor(['store', 'update', 'destroy'], ['check.permission:suppliers,edit']);

        Route::get('suppliers/{supplier}/attachments/{attachment}/download', [SupplierAttachmentController::class, 'download'])
            ->middleware('check.permission:suppliers,view')
            ->name('suppliers.attachments.download');

        Route::apiResource('suppliers.attachments', SupplierAttachmentController::class)
            ->only(['index', 'store', 'show', 'destroy'])
            ->middlewareFor(['index', 'show'], ['check.permission:suppliers,view'])
            ->middlewareFor(['store', 'destroy'], ['check.permission:suppliers,edit']);

        Route::apiResource('suppliers', SupplierController::class)
            ->middlewareFor(['index', 'show'], ['check.permission:suppliers,view'])
            ->middlewareFor(['store'], ['check.permission:suppliers,add'])
            ->middlewareFor(['update'], ['check.permission:suppliers,edit'])
            ->middlewareFor(['destroy'], ['check.permission:suppliers,delete']);
    });
});
