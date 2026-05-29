<?php

use App\Jobs\BootstrapTenantItemTypes;
use App\Models\Tenant;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tenants:sync-item-types', function () {
    $count = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$count): void {
        BootstrapTenantItemTypes::dispatchSync($tenant);
        $this->info("Synced item types for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed.");
})->purpose('Seed the item_types catalog for all existing tenants');
