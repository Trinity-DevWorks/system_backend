<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Accounting\Services\ChartOfAccountsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class BootstrapTenantChartOfAccounts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected TenantWithDatabase $tenant
    ) {}

    public function handle(ChartOfAccountsService $chartOfAccountsService): void
    {
        $this->tenant->run(function () use ($chartOfAccountsService): void {
            $chartOfAccountsService->seedFromTemplate();
        });
    }
}
