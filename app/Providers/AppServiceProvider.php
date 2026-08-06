<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\VirusScanner;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Brand\Models\Brand;
use App\Modules\Branch\Models\Branch;
use App\Modules\Category\Models\Category;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Models\CustomerBalance;
use App\Modules\Customer\Models\CustomerContact;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Item\Models\RecipeItem;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use App\Modules\Supplier\Models\SupplierBalance;
use App\Modules\Supplier\Models\SupplierContact;
use App\Modules\Supplier\Models\SupplierGroup;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\TenantSetting\Models\TenantSetting;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\VirusScanning\ClamAvVirusScanner;
use App\Services\VirusScanning\NullVirusScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VirusScanner::class, function (): VirusScanner {
            return match ((string) config('attachments.virus_scan.driver', 'null')) {
                'clamav' => new ClamAvVirusScanner,
                default => new NullVirusScanner,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLoginRateLimiting();
        $this->configurePasswordResetRateLimiting();

        Relation::enforceMorphMap([
            'tenant' => Tenant::class,
            'user' => User::class,
            'audit' => Audit::class,
            'attachment' => Attachment::class,
            'brand' => Brand::class,
            'branch' => Branch::class,
            'category' => Category::class,
            'company_profile' => CompanyProfile::class,
            'customer' => Customer::class,
            'customer_group' => CustomerGroup::class,
            'customer_address' => CustomerAddress::class,
            'customer_balance' => CustomerBalance::class,
            'salesman' => Salesman::class,
            'supplier' => Supplier::class,
            'supplier_group' => SupplierGroup::class,
            'supplier_address' => SupplierAddress::class,
            'customer_contact' => CustomerContact::class,
            'supplier_contact' => SupplierContact::class,
            'vat_group' => VatGroup::class,
            'warehouse' => Warehouse::class,
            'currency' => Currency::class,
            'payment_method' => PaymentMethod::class,
            'payment_term' => PaymentTerm::class,
            'permission' => Permission::class,
            'role' => Role::class,
            'item' => Item::class,
            'bundle_item' => BundleItem::class,
            'recipe' => Recipe::class,
            'recipe_item' => RecipeItem::class,
            'stock_transfer' => StockTransfer::class,
            'stock_transfer_line' => StockTransferLine::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_line' => PurchaseOrderLine::class,
            'item_warehouse_replenishment' => ItemWarehouseReplenishment::class,
            'unit_group' => UnitGroup::class,
            'unit_of_measurement' => UnitOfMeasurement::class,
            'supplier_balance' => SupplierBalance::class,
            'supplier_item' => SupplierItem::class,
            'tenant_setting' => TenantSetting::class,
        ]);
    }

    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $perMinute = (int) config('security.login_rate_limit_per_minute', 10);

            return Limit::perMinute(max(1, $perMinute))->by($request->ip());
        });
    }

    private function configurePasswordResetRateLimiting(): void
    {
        RateLimiter::for('password-reset', function (Request $request) {
            $perMinute = (int) config('security.password_reset_rate_limit_per_minute', 5);
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinute(max(1, $perMinute))->by($request->ip().'|'.$email);
        });
    }
}
