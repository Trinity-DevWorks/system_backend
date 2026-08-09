<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Modules\Branch\Services\BranchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

class BootstrapTenantDefaultBranch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected TenantWithDatabase $tenant
    ) {}

    public function handle(BranchService $branchService): void
    {
        $this->tenant->run(function () use ($branchService): void {
            $branchService->ensureDefaultBranch();
        });
    }
}
