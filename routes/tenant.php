<?php

declare(strict_types=1);

use App\Http\Controllers\Tenant\AssignedModuleController;
use App\Modules\Audit\Http\Controllers\AuditController;
use App\Modules\Brand\Http\Controllers\BrandController;
use App\Modules\Category\Http\Controllers\CategoryController;
use App\Modules\CompanyProfile\Http\Controllers\CompanyProfileAttachmentController;
use App\Modules\CompanyProfile\Http\Controllers\CompanyProfileController;
use App\Modules\Currency\Http\Controllers\CurrencyController;
use App\Modules\Customer\Http\Controllers\CustomerAddressController;
use App\Modules\Customer\Http\Controllers\CustomerAttachmentController;
use App\Modules\Customer\Http\Controllers\CustomerContactController;
use App\Modules\Customer\Http\Controllers\CustomerController;
use App\Modules\Customer\Http\Controllers\CustomerGroupController;
use App\Modules\Customer\Http\Controllers\CustomerLedgerController;
use App\Modules\Inventory\Item\Http\Controllers\BundleItemController;
use App\Modules\Inventory\Item\Http\Controllers\ItemAttachmentController;
use App\Modules\Inventory\Item\Http\Controllers\ItemBarcodeController;
use App\Modules\Inventory\Item\Http\Controllers\ItemController;
use App\Modules\Inventory\Item\Http\Controllers\ItemUomController;
use App\Modules\Inventory\Item\Http\Controllers\RecipeController;
use App\Modules\Inventory\Item\Http\Controllers\RecipeItemController;
use App\Modules\Inventory\ItemType\Http\Controllers\ItemTypeController;
use App\Modules\Inventory\Purchasing\Http\Controllers\PurchaseOrderController;
use App\Modules\Inventory\Stock\Http\Controllers\ItemWarehouseReplenishmentController;
use App\Modules\Inventory\Stock\Http\Controllers\PurchasingAlertController;
use App\Modules\Inventory\Stock\Http\Controllers\StockBalanceController;
use App\Modules\Inventory\Stock\Http\Controllers\StockMovementController;
use App\Modules\Inventory\Stock\Http\Controllers\StockTransferController;
use App\Modules\Inventory\UnitGroup\Http\Controllers\UnitGroupController;
use App\Modules\Inventory\UnitOfMeasurement\Http\Controllers\UnitOfMeasurementController;
use App\Modules\PaymentMethod\Http\Controllers\PaymentMethodController;
use App\Modules\PaymentTerm\Http\Controllers\PaymentTermController;
use App\Modules\Rbac\Http\Controllers\ForgotPasswordController;
use App\Modules\Rbac\Http\Controllers\LoginController;
use App\Modules\Rbac\Http\Controllers\LogoutController;
use App\Modules\Rbac\Http\Controllers\PermissionController;
use App\Modules\Rbac\Http\Controllers\ResetPasswordController;
use App\Modules\Rbac\Http\Controllers\RoleController;
use App\Modules\Rbac\Http\Controllers\UserController;
use App\Modules\Rbac\Http\Controllers\UserRoleController;
use App\Modules\Salesman\Http\Controllers\SalesmanAttachmentController;
use App\Modules\Salesman\Http\Controllers\SalesmanController;
use App\Modules\Supplier\Http\Controllers\SupplierAddressController;
use App\Modules\Supplier\Http\Controllers\SupplierAttachmentController;
use App\Modules\Supplier\Http\Controllers\SupplierContactController;
use App\Modules\Supplier\Http\Controllers\SupplierController;
use App\Modules\Supplier\Http\Controllers\SupplierGroupController;
use App\Modules\Supplier\Http\Controllers\SupplierItemController;
use App\Modules\Supplier\Http\Controllers\SupplierLedgerController;
use App\Modules\TenantSetting\Http\Controllers\TenantSettingController;
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
| Module entitlements (ensure.module) gate product packs; RBAC gates user actions.
|
*/

Route::middleware([
    'api',
    InitializeTenancyByDomain::class,
    PreventAccessFromCentralDomains::class,
])->group(function () {
    Route::get('/', function () {
        return response()->json([
            'tenant_id' => tenant('id'),
            'message' => 'Tenant application.',
        ]);
    });

    Route::post('auth/login', LoginController::class)
        ->middleware('throttle:login');
    Route::post('auth/forgot-password', ForgotPasswordController::class)
        ->middleware('throttle:password-reset');
    Route::post('auth/reset-password', ResetPasswordController::class)
        ->middleware('throttle:password-reset');

    Route::middleware(['auth:sanctum', 'ensure.active'])->group(function () {
        Route::post('auth/logout', LogoutController::class)
            ->middleware('throttle:60,1');

        Route::get('tenant/assigned-modules', AssignedModuleController::class);

        Route::middleware(['ensure.module:core'])->group(function () {
            Route::get('permissions', [PermissionController::class, 'index'])
                ->middleware('check.permission:permissions,view');

            Route::get('audits', [AuditController::class, 'index'])
                ->middleware('check.permission:audits,view');
            Route::get('audits/export', [AuditController::class, 'export'])
                ->middleware('check.permission:audits,export');
            Route::get('audits/{audit}', [AuditController::class, 'show'])
                ->middleware('check.permission:audits,view');

            Route::apiResource('roles', RoleController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:roles,view'])
                ->middlewareFor(['store'], ['check.permission:roles,add'])
                ->middlewareFor(['update'], ['check.permission:roles,edit'])
                ->middlewareFor(['destroy'], ['check.permission:roles,delete']);

            Route::apiResource('users', UserController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:users,view'])
                ->middlewareFor(['store'], ['check.permission:users,add'])
                ->middlewareFor(['update'], ['check.permission:users,edit'])
                ->middlewareFor(['destroy'], ['check.permission:users,delete']);
            Route::patch('users/{user}/role', [UserRoleController::class, 'update'])
                ->middleware('check.permission:users,edit');

            Route::get('company-profile', [CompanyProfileController::class, 'show'])
                ->middleware('check.permission:company_profile,view');
            Route::put('company-profile', [CompanyProfileController::class, 'update'])
                ->middleware('check.permission:company_profile,edit');

            Route::get('tenant-settings', [TenantSettingController::class, 'show']);
            Route::put('tenant-settings', [TenantSettingController::class, 'update'])
                ->middleware('check.permission:tenant_settings,edit');

            Route::get('company-profile/attachments/{attachment}/download', [CompanyProfileAttachmentController::class, 'download'])
                ->middleware('check.permission:company_profile,view')
                ->name('company-profile.attachments.download');
            Route::get('company-profile/attachments/{attachment}/view', [CompanyProfileAttachmentController::class, 'view'])
                ->middleware('check.permission:company_profile,view')
                ->name('company-profile.attachments.view');
            Route::put('company-profile/attachments/{attachment}/primary', [CompanyProfileAttachmentController::class, 'setPrimary'])
                ->middleware('check.permission:company_profile,edit')
                ->name('company-profile.attachments.set-primary');

            Route::get('company-profile/attachments', [CompanyProfileAttachmentController::class, 'index'])
                ->middleware('check.permission:company_profile,view');
            Route::post('company-profile/attachments', [CompanyProfileAttachmentController::class, 'store'])
                ->middleware('check.permission:company_profile,edit');
            Route::get('company-profile/attachments/{attachment}', [CompanyProfileAttachmentController::class, 'show'])
                ->middleware('check.permission:company_profile,view');
            Route::delete('company-profile/attachments/{attachment}', [CompanyProfileAttachmentController::class, 'destroy'])
                ->middleware('check.permission:company_profile,edit');
        });

        Route::middleware(['ensure.module:master_data'])->group(function () {
            Route::apiResource('brands', BrandController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:brands,view'])
                ->middlewareFor(['store'], ['check.permission:brands,add'])
                ->middlewareFor(['update'], ['check.permission:brands,edit'])
                ->middlewareFor(['destroy'], ['check.permission:brands,delete']);

            Route::apiResource('categories', CategoryController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:categories,view'])
                ->middlewareFor(['store'], ['check.permission:categories,add'])
                ->middlewareFor(['update'], ['check.permission:categories,edit'])
                ->middlewareFor(['destroy'], ['check.permission:categories,delete']);

            Route::apiResource('vat-groups', VatGroupController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:vat_groups,view'])
                ->middlewareFor(['store'], ['check.permission:vat_groups,add'])
                ->middlewareFor(['update'], ['check.permission:vat_groups,edit'])
                ->middlewareFor(['destroy'], ['check.permission:vat_groups,delete']);

            Route::get('currencies/pair-rates', [CurrencyController::class, 'pairRates'])
                ->middleware('check.permission:currencies,view');
            Route::get('currencies/{currency}/rate-history', [CurrencyController::class, 'rateHistory'])
                ->middleware('check.permission:currencies,view');
            Route::post('currencies/fetch-exchange-rates', [CurrencyController::class, 'fetchExchangeRates'])
                ->middleware('check.permission:currencies,view');
            Route::apiResource('currencies', CurrencyController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:currencies,view'])
                ->middlewareFor(['store'], ['check.permission:currencies,add'])
                ->middlewareFor(['update'], ['check.permission:currencies,edit'])
                ->middlewareFor(['destroy'], ['check.permission:currencies,delete']);

            Route::apiResource('payment-methods', PaymentMethodController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:payment_methods,view'])
                ->middlewareFor(['store'], ['check.permission:payment_methods,add'])
                ->middlewareFor(['update'], ['check.permission:payment_methods,edit'])
                ->middlewareFor(['destroy'], ['check.permission:payment_methods,delete']);

            Route::apiResource('payment-terms', PaymentTermController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:payment_terms,view'])
                ->middlewareFor(['store'], ['check.permission:payment_terms,add'])
                ->middlewareFor(['update'], ['check.permission:payment_terms,edit'])
                ->middlewareFor(['destroy'], ['check.permission:payment_terms,delete']);
        });

        Route::middleware(['ensure.module:inventory'])->group(function () {
            Route::apiResource('warehouses', WarehouseController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:warehouses,view'])
                ->middlewareFor(['store'], ['check.permission:warehouses,add'])
                ->middlewareFor(['update'], ['check.permission:warehouses,edit'])
                ->middlewareFor(['destroy'], ['check.permission:warehouses,delete']);

            Route::get('stock/balances', [StockBalanceController::class, 'index'])
                ->middleware('check.permission:stock,view');
            Route::get('stock/balances/show', [StockBalanceController::class, 'show'])
                ->middleware('check.permission:stock,view');
            Route::get('stock/movements', [StockMovementController::class, 'index'])
                ->middleware('check.permission:stock,view');
            Route::post('stock/adjustments', [StockMovementController::class, 'storeAdjustment'])
                ->middleware('check.permission:stock,edit');

            Route::get('stock/purchasing-alerts/summary', [PurchasingAlertController::class, 'summary'])
                ->middleware('check.permission:stock,view');
            Route::get('stock/purchasing-alerts', [PurchasingAlertController::class, 'index'])
                ->middleware('check.permission:stock,view');

            Route::get('stock/transfers', [StockTransferController::class, 'index'])
                ->middleware('check.permission:stock,view');
            Route::post('stock/transfers', [StockTransferController::class, 'store'])
                ->middleware('check.permission:stock,edit');
            Route::get('stock/transfers/{stock_transfer}', [StockTransferController::class, 'show'])
                ->middleware('check.permission:stock,view');
            Route::put('stock/transfers/{stock_transfer}', [StockTransferController::class, 'update'])
                ->middleware('check.permission:stock,edit');
            Route::delete('stock/transfers/{stock_transfer}', [StockTransferController::class, 'destroy'])
                ->middleware('check.permission:stock,edit');
            Route::put('stock/transfers/{stock_transfer}/lines/sync', [StockTransferController::class, 'syncLines'])
                ->middleware('check.permission:stock,edit');
            Route::post('stock/transfers/{stock_transfer}/post', [StockTransferController::class, 'post'])
                ->middleware('check.permission:stock,edit');
            Route::post('stock/transfers/{stock_transfer}/cancel', [StockTransferController::class, 'cancel'])
                ->middleware('check.permission:stock,edit');

            Route::get('stock/purchase-orders', [PurchaseOrderController::class, 'index'])
                ->middleware('check.permission:stock,view');
            Route::post('stock/purchase-orders', [PurchaseOrderController::class, 'store'])
                ->middleware('check.permission:stock,edit');
            Route::post('stock/purchase-orders/from-alerts', [PurchaseOrderController::class, 'fromAlerts'])
                ->middleware('check.permission:stock,edit');
            Route::get('stock/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'show'])
                ->middleware('check.permission:stock,view');
            Route::put('stock/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'update'])
                ->middleware('check.permission:stock,edit');
            Route::delete('stock/purchase-orders/{purchase_order}', [PurchaseOrderController::class, 'destroy'])
                ->middleware('check.permission:stock,edit');
            Route::put('stock/purchase-orders/{purchase_order}/lines/sync', [PurchaseOrderController::class, 'syncLines'])
                ->middleware('check.permission:stock,edit');
            Route::post('stock/purchase-orders/{purchase_order}/confirm', [PurchaseOrderController::class, 'confirm'])
                ->middleware('check.permission:stock,edit');
            Route::post('stock/purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])
                ->middleware('check.permission:stock,edit');
            Route::get('stock/purchase-orders/{purchase_order}/pdf', [PurchaseOrderController::class, 'pdf'])
                ->middleware('check.permission:stock,view');
            Route::post('stock/purchase-orders/{purchase_order}/mark-sent', [PurchaseOrderController::class, 'markAsSent'])
                ->middleware('check.permission:stock,edit');

            Route::get('unit-groups/{unit_group}/units', [UnitGroupController::class, 'units'])
                ->middleware('check.permission:unit_groups,view');
            Route::apiResource('unit-groups', UnitGroupController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:unit_groups,view'])
                ->middlewareFor(['store'], ['check.permission:unit_groups,add'])
                ->middlewareFor(['update'], ['check.permission:unit_groups,edit'])
                ->middlewareFor(['destroy'], ['check.permission:unit_groups,delete']);

            Route::apiResource('unit-of-measurements', UnitOfMeasurementController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:unit_of_measurements,view'])
                ->middlewareFor(['store'], ['check.permission:unit_of_measurements,add'])
                ->middlewareFor(['update'], ['check.permission:unit_of_measurements,edit'])
                ->middlewareFor(['destroy'], ['check.permission:unit_of_measurements,delete']);

            Route::get('item-types', [ItemTypeController::class, 'index'])
                ->middleware('check.permission:items,view');

            Route::get('items/{item}/item-uoms', [ItemUomController::class, 'index'])
                ->middleware('check.permission:items,view');
            Route::post('items/{item}/item-uoms', [ItemUomController::class, 'store'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/item-uoms/{item_uom}', [ItemUomController::class, 'update'])
                ->middleware('check.permission:items,edit');
            Route::delete('items/{item}/item-uoms/{item_uom}', [ItemUomController::class, 'destroy'])
                ->middleware('check.permission:items,edit');
            Route::get('items/lookup-by-barcode', [ItemBarcodeController::class, 'lookup'])
                ->middleware('check.permission:items,view');
            Route::get('items/{item}/barcodes', [ItemBarcodeController::class, 'index'])
                ->middleware('check.permission:items,view');
            Route::post('items/{item}/barcodes', [ItemBarcodeController::class, 'store'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/barcodes/{item_barcode}', [ItemBarcodeController::class, 'update'])
                ->middleware('check.permission:items,edit');
            Route::delete('items/{item}/barcodes/{item_barcode}', [ItemBarcodeController::class, 'destroy'])
                ->middleware('check.permission:items,edit');

            Route::get('items/{item}/supplier-items', [SupplierItemController::class, 'indexForItem'])
                ->middleware('check.permission:items,view');

            Route::get('items/{item}/warehouse-replenishments', [ItemWarehouseReplenishmentController::class, 'index'])
                ->middleware('check.permission:items,view');
            Route::post('items/{item}/warehouse-replenishments', [ItemWarehouseReplenishmentController::class, 'store'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/warehouse-replenishments/{item_warehouse_replenishment}', [ItemWarehouseReplenishmentController::class, 'update'])
                ->middleware('check.permission:items,edit');
            Route::delete('items/{item}/warehouse-replenishments/{item_warehouse_replenishment}', [ItemWarehouseReplenishmentController::class, 'destroy'])
                ->middleware('check.permission:items,edit');

            Route::get('items/{item}/attachments/{attachment}/download', [ItemAttachmentController::class, 'download'])
                ->middleware('check.permission:items,view')
                ->name('items.attachments.download');
            Route::get('items/{item}/attachments/{attachment}/view', [ItemAttachmentController::class, 'view'])
                ->middleware('check.permission:items,view')
                ->name('items.attachments.view');
            Route::put('items/{item}/attachments/{attachment}/primary', [ItemAttachmentController::class, 'setPrimary'])
                ->middleware('check.permission:items,edit')
                ->name('items.attachments.set-primary');

            Route::apiResource('items.attachments', ItemAttachmentController::class)
                ->only(['index', 'store', 'show', 'destroy'])
                ->middlewareFor(['index', 'show'], ['check.permission:items,view'])
                ->middlewareFor(['store', 'destroy'], ['check.permission:items,edit']);

            Route::get('items/{item}/bundle-items', [BundleItemController::class, 'index'])
                ->middleware('check.permission:items,view');
            Route::post('items/{item}/bundle-items', [BundleItemController::class, 'store'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/bundle-items/sync', [BundleItemController::class, 'sync'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/bundle-items/{bundle_item}', [BundleItemController::class, 'update'])
                ->middleware('check.permission:items,edit');
            Route::delete('items/{item}/bundle-items/{bundle_item}', [BundleItemController::class, 'destroy'])
                ->middleware('check.permission:items,edit');

            Route::get('items/{item}/recipe', [RecipeController::class, 'show'])
                ->middleware('check.permission:items,view');
            Route::put('items/{item}/recipe', [RecipeController::class, 'upsert'])
                ->middleware('check.permission:items,edit');

            Route::get('items/{item}/recipe-items', [RecipeItemController::class, 'index'])
                ->middleware('check.permission:items,view');
            Route::post('items/{item}/recipe-items', [RecipeItemController::class, 'store'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/recipe-items/sync', [RecipeItemController::class, 'sync'])
                ->middleware('check.permission:items,edit');
            Route::put('items/{item}/recipe-items/{recipe_item}', [RecipeItemController::class, 'update'])
                ->middleware('check.permission:items,edit');
            Route::delete('items/{item}/recipe-items/{recipe_item}', [RecipeItemController::class, 'destroy'])
                ->middleware('check.permission:items,edit');

            Route::apiResource('items', ItemController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:items,view'])
                ->middlewareFor(['store'], ['check.permission:items,add'])
                ->middlewareFor(['update'], ['check.permission:items,edit'])
                ->middlewareFor(['destroy'], ['check.permission:items,delete']);
        });

        Route::middleware(['ensure.module:sales'])->group(function () {
            Route::get('salesmen/{salesman}/attachments/{attachment}/download', [SalesmanAttachmentController::class, 'download'])
                ->middleware('check.permission:salesmen,view')
                ->name('salesmen.attachments.download');
            Route::get('salesmen/{salesman}/attachments/{attachment}/view', [SalesmanAttachmentController::class, 'view'])
                ->middleware('check.permission:salesmen,view')
                ->name('salesmen.attachments.view');

            Route::apiResource('salesmen.attachments', SalesmanAttachmentController::class)
                ->only(['index', 'store', 'show', 'destroy'])
                ->middlewareFor(['index', 'show'], ['check.permission:salesmen,view'])
                ->middlewareFor(['store', 'destroy'], ['check.permission:salesmen,edit']);

            Route::apiResource('salesmen', SalesmanController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:salesmen,view'])
                ->middlewareFor(['store'], ['check.permission:salesmen,add'])
                ->middlewareFor(['update'], ['check.permission:salesmen,edit'])
                ->middlewareFor(['destroy'], ['check.permission:salesmen,delete']);

            Route::apiResource('customer-groups', CustomerGroupController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:customer_groups,view'])
                ->middlewareFor(['store'], ['check.permission:customer_groups,add'])
                ->middlewareFor(['update'], ['check.permission:customer_groups,edit'])
                ->middlewareFor(['destroy'], ['check.permission:customer_groups,delete']);

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
            Route::get('customers/{customer}/attachments/{attachment}/view', [CustomerAttachmentController::class, 'view'])
                ->middleware('check.permission:customers,view')
                ->name('customers.attachments.view');

            Route::apiResource('customers.attachments', CustomerAttachmentController::class)
                ->only(['index', 'store', 'show', 'destroy'])
                ->middlewareFor(['index', 'show'], ['check.permission:customers,view'])
                ->middlewareFor(['store', 'destroy'], ['check.permission:customers,edit']);

            Route::apiResource('customers', CustomerController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:customers,view'])
                ->middlewareFor(['store'], ['check.permission:customers,add'])
                ->middlewareFor(['update'], ['check.permission:customers,edit'])
                ->middlewareFor(['destroy'], ['check.permission:customers,delete']);
        });

        Route::middleware(['ensure.module:purchasing'])->group(function () {
            Route::apiResource('supplier-groups', SupplierGroupController::class)
                ->middlewareFor(['index', 'show'], ['check.permission:supplier_groups,view'])
                ->middlewareFor(['store'], ['check.permission:supplier_groups,add'])
                ->middlewareFor(['update'], ['check.permission:supplier_groups,edit'])
                ->middlewareFor(['destroy'], ['check.permission:supplier_groups,delete']);

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

            Route::apiResource('suppliers.supplier-items', SupplierItemController::class)
                ->scoped()
                ->middlewareFor(['index', 'show'], ['check.permission:suppliers,view'])
                ->middlewareFor(['store', 'update', 'destroy'], ['check.permission:suppliers,edit']);

            Route::get('suppliers/{supplier}/attachments/{attachment}/download', [SupplierAttachmentController::class, 'download'])
                ->middleware('check.permission:suppliers,view')
                ->name('suppliers.attachments.download');
            Route::get('suppliers/{supplier}/attachments/{attachment}/view', [SupplierAttachmentController::class, 'view'])
                ->middleware('check.permission:suppliers,view')
                ->name('suppliers.attachments.view');

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
});
