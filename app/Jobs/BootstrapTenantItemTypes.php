<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Inventory\ItemType\Services\ItemTypeCatalogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class BootstrapTenantItemTypes implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected TenantWithDatabase $tenant
    ) {}

    public function handle(ItemTypeCatalogService $itemTypeCatalogService): void
    {
        $this->tenant->run(function () use ($itemTypeCatalogService): void {
            $itemTypeCatalogService->syncFromConfig();
        });
    }
}
