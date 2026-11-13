<?php

use App\Jobs\BootstrapTenantDefaultBranch;
use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantRbac;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Jobs\BootstrapTenantWalkInCustomer;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\FixedAsset\Services\DepreciationRunService;
use App\Modules\Accounting\Services\AccountingBackfillService;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Enums\DeferralStatus;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Services\DeferralPostingService;
use App\Modules\Inventory\PriceChange\Services\PriceChangeService;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\Rbac\Models\Role;
use App\Modules\RecurringEntry\Enums\RecurringTemplateStatus;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\RecurringEntry\Services\RecurringGenerationService;
use App\Services\ModuleEntitlementService;
use App\Support\TenantClock;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

Artisan::command('tenants:sync-walk-in-customer', function () {
    $count = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use (&$count): void {
        BootstrapTenantWalkInCustomer::dispatchSync($tenant);
        $this->info("Ensured walk-in customer for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed.");
})->purpose('Seed the walk-in / cash customer for all existing tenants');

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
        $skipReason = null;

        $tenant->run(function () use (&$assigned, &$skipReason): void {
            $branchService = app(BranchService::class);
            $defaultId = $branchService->defaultBranchId();
            $fallbackRoleId = Role::query()
                ->where('name', 'Admin')
                ->value('id')
                ?? Role::query()->orderBy('id')->value('id');

            if ($fallbackRoleId === null) {
                $skipReason = 'no roles seeded yet. Run tenants:sync-rbac first.';

                return;
            }

            User::query()->orderBy('created_at')->each(function (User $user) use ($defaultId, $fallbackRoleId, &$assigned): void {
                if ($user->branches()->exists()) {
                    return;
                }

                $user->branches()->attach($defaultId, ['role_id' => (int) $fallbackRoleId]);
                $assigned++;
            });
        });

        if ($skipReason !== null) {
            $this->warn("Skipped tenant [{$tenant->id}] — {$skipReason}");

            return;
        }

        $this->info("Synced user branches for tenant [{$tenant->id}]");
        $count++;
    });

    $this->info("Done. {$count} tenant(s) processed. {$assigned} user assignment(s) created.");
})->purpose('Assign the default branch (with a role) to users without any branch');

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

/*
|--------------------------------------------------------------------------
| notifications:low-stock-digest
|--------------------------------------------------------------------------
|
| What: Walks each tenant and sends a cooldown-aware low-stock digest via DomainNotificationPublisher.
| Where: Manual run or daily schedule (07:00) in bootstrap/app.php.
| Why: Converts purchasing-alert query data into proactive in-app + email notifications without spamming.
|
*/
Artisan::command('notifications:low-stock-digest', function (): void {
    $command = $this;
    $sentTenants = 0;
    $skipped = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use ($command, &$sentTenants, &$skipped): void {
        $tenant->run(function () use ($tenant, $command, &$sentTenants, &$skipped): void {
            if (! Schema::hasTable('notifications')) {
                $command->warn("Skipped tenant [{$tenant->id}] — notifications table missing. Run tenant migrations.");
                $skipped++;

                return;
            }

            /** @var DomainNotificationPublisher $publisher */
            $publisher = app(DomainNotificationPublisher::class);
            $result = $publisher->lowStockDigest();

            if ($result['sent']) {
                $sentTenants++;
                $command->info("Tenant [{$tenant->id}]: digest sent ({$result['alert_count']} alert(s)).");
            } else {
                $skipped++;
                $reason = $result['reason'] ?? 'skipped';
                $command->info("Tenant [{$tenant->id}]: skipped ({$reason}, alerts={$result['alert_count']}).");
            }
        });
    });

    $this->info("Done. Sent={$sentTenants}. Skipped={$skipped}.");
})->purpose('Send low-stock digest notifications for each tenant (cooldown-aware)');

/*
|--------------------------------------------------------------------------
| notifications:lot-expiry-digest
|--------------------------------------------------------------------------
|
| What: Walks each tenant and sends a cooldown-aware digest of on-hand lots
| that are expired or expire within the configured window.
| Where: Manual run or daily schedule (07:15) in bootstrap/app.php.
|
*/
Artisan::command('notifications:lot-expiry-digest', function (): void {
    $command = $this;
    $sentTenants = 0;
    $skipped = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use ($command, &$sentTenants, &$skipped): void {
        $tenant->run(function () use ($tenant, $command, &$sentTenants, &$skipped): void {
            if (! Schema::hasTable('notifications') || ! Schema::hasTable('inventory_lots')) {
                $command->warn("Skipped tenant [{$tenant->id}] — required tables missing. Run tenant migrations.");
                $skipped++;

                return;
            }

            /** @var DomainNotificationPublisher $publisher */
            $publisher = app(DomainNotificationPublisher::class);
            $result = $publisher->lotExpiryDigest();

            if ($result['sent']) {
                $sentTenants++;
                $command->info(
                    "Tenant [{$tenant->id}]: digest sent ({$result['lot_count']} lot(s), {$result['expired_count']} expired)."
                );
            } else {
                $skipped++;
                $reason = $result['reason'] ?? 'skipped';
                $command->info(
                    "Tenant [{$tenant->id}]: skipped ({$reason}, lots={$result['lot_count']})."
                );
            }
        });
    });

    $this->info("Done. Sent={$sentTenants}. Skipped={$skipped}.");
})->purpose('Send lot-expiry digest notifications for each tenant (cooldown-aware)');

/*
|--------------------------------------------------------------------------
| fixed-assets:depreciate
|--------------------------------------------------------------------------
|
| Posts the previous month of depreciation for every tenant that turned on
| automatic depreciation. A month that is already posted is left alone.
|
*/
Artisan::command('fixed-assets:depreciate {--month=}', function (): void {
    $command = $this;
    $month = $this->option('month');
    $month = is_string($month) && $month !== '' ? $month : null;
    $posted = 0;
    $skipped = 0;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use ($command, $month, &$posted, &$skipped): void {
        $tenant->run(function () use ($tenant, $command, $month, &$posted, &$skipped): void {
            if (! Schema::hasTable('fixed_assets') || ! Schema::hasColumn('company_settings', 'auto_depreciation')) {
                $command->warn("Skipped tenant [{$tenant->id}] — fixed assets are not migrated.");
                $skipped++;

                return;
            }

            try {
                $result = app(DepreciationRunService::class)->postAutomatic($month);
            } catch (Throwable $exception) {
                $skipped++;
                $command->warn("Tenant [{$tenant->id}]: {$exception->getMessage()}");

                return;
            }

            if ($result['posted']) {
                $posted++;
                $command->info("Tenant [{$tenant->id}]: depreciation posted.");
            } else {
                $skipped++;
                $command->info("Tenant [{$tenant->id}]: skipped ({$result['reason']}).");
            }
        });
    });

    $this->info("Done. Posted={$posted}. Skipped={$skipped}.");
})->purpose('Post monthly depreciation for tenants that run it automatically');

/*
|--------------------------------------------------------------------------
| notifications:prune
|--------------------------------------------------------------------------
|
| Deletes read inbox rows older than the configured retention period in each
| tenant database. Unread notifications are deliberately preserved.
|
*/
Artisan::command('notifications:prune {--days= : Override configured retention days}', function (): void {
    $configuredDays = (int) config('notifications.retention_days', 90);
    $days = max(1, (int) ($this->option('days') ?: $configuredDays));
    $cutoff = now()->subDays($days);
    $total = 0;
    $command = $this;

    Tenant::query()->cursor()->each(function (Tenant $tenant) use ($cutoff, $command, &$total): void {
        $tenant->run(function () use ($cutoff, $tenant, $command, &$total): void {
            if (! Schema::hasTable('notifications')) {
                $command->warn("Skipped tenant [{$tenant->id}] — notifications table missing.");

                return;
            }

            $deleted = DB::table('notifications')
                ->whereNotNull('read_at')
                ->where('created_at', '<', $cutoff)
                ->delete();

            $total += $deleted;
            $command->info("Tenant [{$tenant->id}]: deleted {$deleted} expired read notification(s).");
        });
    });

    $this->info("Done. Retention={$days} day(s). Total deleted={$total}.");
})->purpose('Delete expired read notifications from tenant inboxes');

/*
|--------------------------------------------------------------------------
| accounting:seed-chart / accounting:backfill
|--------------------------------------------------------------------------
|
| seed-chart inserts missing template accounts and unmapped roles; it never overwrites.
| backfill posts journal entries for documents posted before accounting existed.
|
*/
$accountingTenants = function (array $ids) {
    $query = Tenant::query();
    if ($ids !== []) {
        $query->whereIn('id', $ids);
    }

    return $query->cursor();
};

Artisan::command('accounting:seed-chart {--tenant=* : Tenant ids (default: all)} {--template= : Template key from config/chart_of_accounts.php}', function () use ($accountingTenants): void {
    $template = $this->option('template') ?: null;
    $command = $this;

    foreach ($accountingTenants((array) $this->option('tenant')) as $tenant) {
        $result = $tenant->run(fn (): array => app(ChartOfAccountsService::class)->seedFromTemplate($template));
        $command->info("Tenant [{$tenant->id}]: template {$result['template']}, {$result['accounts_created']} account(s), {$result['mappings_created']} mapping(s) created.");
    }
})->purpose('Seed the chart of accounts and account mappings for tenants');

/*
|--------------------------------------------------------------------------
| prices:apply-scheduled
|--------------------------------------------------------------------------
|
| Applies price change documents whose effective date has arrived. Passing
| --date uses that day as the tenant's today, so a change dated tomorrow
| can be applied in a test or a catch-up run. Already-applied documents
| are left alone.
|
*/
Artisan::command('prices:apply-scheduled {--date=} {--tenant=*}', function (): void {
    $dateOption = $this->option('date');
    $date = is_string($dateOption) && $dateOption !== '' ? $dateOption : null;
    $ids = array_values(array_filter((array) $this->option('tenant'), fn ($id): bool => is_string($id) && $id !== ''));
    $tenants = Tenant::query();
    if ($ids !== []) {
        $tenants->whereIn('id', $ids);
    }
    $applied = 0;
    $skipped = 0;
    $command = $this;

    foreach ($tenants->cursor() as $tenant) {
        $tenant->run(function () use ($tenant, $command, $date, &$applied, &$skipped): void {
            if (! Schema::hasTable('price_changes')) {
                $command->warn("Skipped tenant [{$tenant->id}] — price changes are not migrated.");
                $skipped++;

                return;
            }

            try {
                $result = app(PriceChangeService::class)->applyScheduled($date);
            } catch (Throwable $exception) {
                $skipped++;
                Log::error('prices:apply-scheduled failed', ['tenant' => $tenant->id, 'error' => $exception->getMessage()]);
                $command->warn("Tenant [{$tenant->id}]: {$exception->getMessage()}");

                return;
            }
            $applied += $result['applied'];
            $skipped += $result['skipped'];
            $command->info("Tenant [{$tenant->id}]: applied {$result['applied']}, skipped {$result['skipped']} (as of {$result['date']}).");
        });
    }

    $this->info("Done. Applied={$applied}. Skipped={$skipped}.");
})->purpose('Apply price changes whose effective date has arrived');

Artisan::command('accounting:backfill {--tenant=* : Tenant ids (default: all)}', function () use ($accountingTenants): void {
    $command = $this;

    foreach ($accountingTenants((array) $this->option('tenant')) as $tenant) {
        $created = $tenant->run(fn (): ?int => app(ModuleEntitlementService::class)->tenantHas((string) $tenant->id, 'accounting')
            ? app(AccountingBackfillService::class)->run()
            : null);

        if ($created === null) {
            $command->warn("Skipped tenant [{$tenant->id}] — accounting module not enabled.");

            continue;
        }
        $command->info("Tenant [{$tenant->id}]: {$created} journal entr(y/ies) created.");
    }
})->purpose('Create journal entries for documents posted before accounting was enabled');

Artisan::command('accounting:run-periodic {--tenant=* : Tenant ids (default: all)} {--date= : Y-m-d, defaults to tenant today} {--dry-run}', function () use ($accountingTenants): void {
    $command = $this;
    $dateOption = $this->option('date');
    $dryRun = (bool) $this->option('dry-run');

    foreach ($accountingTenants((array) $this->option('tenant')) as $tenant) {
        try {
            $tenant->run(function () use ($tenant, $command, $dateOption, $dryRun): void {
                if (! app(ModuleEntitlementService::class)->tenantHas((string) $tenant->id, 'accounting')) {
                    $command->warn("Skipped tenant [{$tenant->id}] — accounting module not enabled.");

                    return;
                }
                if (! Schema::hasTable('recurring_templates')) {
                    $command->warn("Skipped tenant [{$tenant->id}] — recurring tables are not migrated.");

                    return;
                }

                $asOf = is_string($dateOption) && $dateOption !== ''
                    ? Carbon::parse($dateOption)->startOfDay()
                    : TenantClock::today();

                if ($dryRun) {
                    $templates = RecurringTemplate::query()
                        ->where('status', RecurringTemplateStatus::Active)
                        ->whereNotNull('next_run_date')
                        ->whereDate('next_run_date', '<=', $asOf->toDateString())
                        ->count();
                    $lines = 0;
                    if (Schema::hasTable('deferral_schedule_lines')) {
                        $lines = DeferralSchedule::query()
                            ->where('status', DeferralStatus::Active)
                            ->where('auto_post', true)
                            ->whereHas('lines', fn ($q) => $q
                                ->where('status', DeferralLineStatus::Pending->value)
                                ->whereDate('posting_date', '<=', $asOf->toDateString()))
                            ->count();
                    }
                    $command->info("accounting:run-periodic tenant={$tenant->id} dry_run=1 due_templates={$templates} due_schedules={$lines}");

                    return;
                }

                $recurring = app(RecurringGenerationService::class)->runDue($asOf);
                $deferrals = Schema::hasTable('deferral_schedules')
                    ? app(DeferralPostingService::class)->postAllDue($asOf)
                    : ['posted' => 0, 'failed' => 0];
                $line = "accounting:run-periodic tenant={$tenant->id} generated={$recurring['generated']} failed={$recurring['failed']} deferral_posted={$deferrals['posted']} deferral_failed={$deferrals['failed']}";
                Log::info($line);
                $command->info($line);
            });
        } catch (Throwable $exception) {
            Log::error('accounting:run-periodic tenant='.$tenant->id.' '.$exception->getMessage());
            $command->warn("Tenant [{$tenant->id}]: {$exception->getMessage()}");
        }
    }
})->purpose('Generate due recurring entries and post due deferral recognitions');
