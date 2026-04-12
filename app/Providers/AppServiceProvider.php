<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        Relation::enforceMorphMap([
            'customer' => Customer::class,
            'supplier' => Supplier::class,
        ]);
    }
}
