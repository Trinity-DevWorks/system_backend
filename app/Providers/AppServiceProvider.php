<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
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
            'customer' => Customer::class,
            'supplier' => Supplier::class,
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
