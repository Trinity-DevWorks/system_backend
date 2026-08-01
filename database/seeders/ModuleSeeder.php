<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\ModuleEntitlementService;
use Illuminate\Database\Seeder;

class ModuleSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(ModuleEntitlementService::class);
        $service->syncCatalog();

        // Preserve current full access for tenants that already exist.
        Tenant::query()->each(function (Tenant $tenant) use ($service): void {
            $service->grantAll($tenant);
        });
    }
}
