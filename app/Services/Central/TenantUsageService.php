<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Enums\TenantStatus;
use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Central\TenantUsageSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Collects each tenant's usage into `tenant_usage_snapshots` (hourly and on demand) and lists it.
 * Counting rules match PlanLimitService: active users, all branches and warehouses, non-deleted files.
 */
final class TenantUsageService
{
    public function paginate(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with(['domains', 'plan', 'usage'])
            ->orderBy('name');

        if ($status !== null && in_array($status, TenantStatus::values(), true)) {
            $query->where('status', $status);
        }

        ListPagination::applySearch($query, $search, ['id', 'name'], ['domains' => ['domain']]);

        return $query->paginate($perPage);
    }

    public function refresh(Tenant $tenant): TenantUsageSnapshot
    {
        $values = $tenant->run(function (): array {
            $monthStart = now()->startOfMonth();

            $lastToken = DB::table('personal_access_tokens')->max('last_used_at');
            $lastAudit = Audit::query()->whereNotNull('user_id')->max('created_at');
            $last = collect([$lastToken, $lastAudit])
                ->filter()
                ->map(fn ($value): Carbon => Carbon::parse((string) $value))
                ->max();

            return [
                'active_users' => User::query()->where('is_active', true)->count(),
                'total_users' => User::query()->count(),
                'branches' => Branch::query()->count(),
                'warehouses' => Warehouse::query()->count(),
                'storage_bytes' => (int) Attachment::query()->sum('file_size'),
                'sales_invoices_month' => DB::table('sales_invoices')->where('created_at', '>=', $monthStart)->count(),
                'purchase_invoices_month' => DB::table('purchase_invoices')->where('created_at', '>=', $monthStart)->count(),
                'last_activity_at' => $last,
            ];
        });

        /** @var TenantUsageSnapshot */
        return TenantUsageSnapshot::query()->updateOrCreate(
            ['tenant_id' => (string) $tenant->id],
            [...$values, 'computed_at' => now()],
        );
    }

    /**
     * Refreshes every tenant; one broken tenant schema does not stop the others.
     *
     * @return array{refreshed: int, failed: list<string>}
     */
    public function refreshAll(): array
    {
        $refreshed = 0;
        $failed = [];

        Tenant::query()->orderBy('id')->each(function (Tenant $tenant) use (&$refreshed, &$failed): void {
            try {
                $this->refresh($tenant);
                $refreshed++;
            } catch (\Throwable $e) {
                $failed[] = (string) $tenant->id;
                Log::warning('Tenant usage refresh failed', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            }
        });

        return ['refreshed' => $refreshed, 'failed' => $failed];
    }
}
