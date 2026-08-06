<?php

use App\Jobs\BootstrapTenantDefaultBranch;
use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantRbac;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

Artisan::command('tenants:sync-unit-catalog', function () {
    $count = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$count): void {
        BootstrapTenantUnitCatalog::dispatchSync($tenant);
        $this->info("Synced unit catalog for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed.");
})->purpose('Seed default unit groups and UOMs for all existing tenants');

Artisan::command('tenants:sync-default-branch', function () {
    $count = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$count): void {
        BootstrapTenantDefaultBranch::dispatchSync($tenant);
        $this->info("Ensured default branch for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed.");
})->purpose('Ensure each tenant has a default Main branch');

Artisan::command('tenants:sync-user-branches', function () {
    $count = 0;
    $assigned = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$count, &$assigned): void {
        $tenant->run(function () use (&$assigned): void {
            $branchService = app(BranchService::class);
            $defaultId = $branchService->defaultBranchId();

            User::query()->orderBy('created_at')->each(function (User $user) use ($defaultId, &$assigned): void {
                if ($user->branches()->exists()) {
                    return;
                }

                $user->branches()->attach($defaultId);
                $assigned++;
            });
        });

        $this->info("Synced user branches for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed. {$assigned} user assignment(s) created.");
})->purpose('Assign the default branch to users without any branch');

Artisan::command('tenants:sync-rbac', function () {
    $count = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$count): void {
        $ownerId = $tenant->run(function (): ?string {
            $owner = User::query()->orderBy('created_at')->value('id');

            return $owner !== null ? (string) $owner : null;
        });

        if ($ownerId === null) {
            $this->warn("Skipped tenant [{$tenant->id}] — no users.");

            return;
        }

        BootstrapTenantRbac::dispatchSync($tenant, $ownerId);
        $this->info("Synced RBAC catalog for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed.");
})->purpose('Sync permission catalog (including audits) for all existing tenants');

/*
|--------------------------------------------------------------------------
| audits:prune
|--------------------------------------------------------------------------
|
| What: Deletes audit rows older than retention_days from each tenant DB.
| Where: Invoked manually or by the nightly schedule in bootstrap/app.php.
| Why: Compliance retention without allowing Eloquent delete on immutable Audit rows.
|
*/
Artisan::command('audits:prune {--days= : Override retention days from config}', function (): void {
    $daysOption = $this->option('days');
    $days = $daysOption !== null && $daysOption !== ''
        ? max(1, (int) $daysOption)
        : max(1, (int) config('audit.retention_days', 730));

    $cutoff = now()->subDays($days);
    $table = config('audit.drivers.database.table', 'audits');
    $total = 0;
    $command = $this;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use ($cutoff, $table, $command, &$total): void {
        $tenant->run(function () use ($cutoff, $table, $tenant, $command, &$total): void {
            if (! Schema::hasTable($table)) {
                $command->warn("Skipped tenant [{$tenant->id}] — audits table missing.");

                return;
            }

            $deleted = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->delete();

            $total += $deleted;
            $command->info("Tenant [{$tenant->id}]: deleted {$deleted} audit row(s).");
        });
    });

    $this->info("Done. Retention={$days} day(s). Total deleted={$total}.");
})->purpose('Delete audit rows older than the configured retention period (per tenant DB)');
