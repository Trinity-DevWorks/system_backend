<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Category\Models\Category;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Models\CustomerContact;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\SubCategory\Models\SubCategory;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use App\Modules\Supplier\Models\SupplierContact;
use App\Modules\Supplier\Models\SupplierGroup;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Models\Warehouse;
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
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureLoginRateLimiting();

        Relation::enforceMorphMap([
            'user' => User::class,
            'category' => Category::class,
            'sub_category' => SubCategory::class,
            'customer' => Customer::class,
            'customer_group' => CustomerGroup::class,
            'customer_address' => CustomerAddress::class,
            'supplier' => Supplier::class,
            'supplier_group' => SupplierGroup::class,
            'supplier_address' => SupplierAddress::class,
            'customer_contact' => CustomerContact::class,
            'supplier_contact' => SupplierContact::class,
            'vat_group' => VatGroup::class,
            'warehouse' => Warehouse::class,
            'currency' => Currency::class,
            'permission' => Permission::class,
            'role' => Role::class,
            'item' => Item::class,
            'unit_group' => UnitGroup::class,
            'unit_of_measurement' => UnitOfMeasurement::class,
        ]);
    }

    private function configureLoginRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $perMinute = (int) config('security.login_rate_limit_per_minute', 10);

            return Limit::perMinute(max(1, $perMinute))->by($request->ip());
        });
    }
}
