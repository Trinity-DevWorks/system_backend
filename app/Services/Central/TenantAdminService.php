<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\ModuleEntitlementService;
use App\Support\ListPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Central tenant administration: list, detail, rename, suspend/activate, modules.
 */
final class TenantAdminService
{
    public function __construct(
        private readonly ModuleEntitlementService $modules,
        private readonly AuditWriter $auditWriter,
    ) {}

    public function paginateForTable(?string $search, ?string $status, int $perPage): LengthAwarePaginator
    {
        $query = Tenant::query()
            ->with(['domains', 'modules'])
            ->orderByDesc('created_at');

        if ($status !== null && in_array($status, TenantStatus::values(), true)) {
            $query->where('status', $status);
        }

        ListPagination::applySearch($query, $search, ['id', 'name'], ['domains' => ['domain']]);

        return $query->paginate($perPage);
    }

    public function find(string $tenantId): Tenant
    {
        $tenant = Tenant::query()->with('domains')->whereKey($tenantId)->first();

        if ($tenant === null) {
            abort(404, 'Tenant not found.', ['X-Error-Code' => 'TENANT_NOT_FOUND']);
        }

        return $tenant;
    }

    /**
     * First user in the tenant schema (the owner created at provisioning).
     *
     * @return array{id: string, name: string, email: string|null}|null
     */
    public function owner(Tenant $tenant): ?array
    {
        try {
            return $tenant->run(function (): ?array {
                $user = User::query()->orderBy('created_at')->first(['id', 'name', 'email']);

                return $user !== null
                    ? ['id' => (string) $user->id, 'name' => (string) $user->name, 'email' => $user->email]
                    : null;
            });
        } catch (\Throwable) {
            return null;
        }
    }

    public function update(Tenant $tenant, string $name): Tenant
    {
        $tenant->update(['name' => $name]);
        CentralOverviewService::forget();

        return $tenant->refresh()->load('domains');
    }

    public function updateStatus(Tenant $tenant, TenantStatus $status, ?string $reason): Tenant
    {
        if ($status === TenantStatus::Suspended) {
            $tenant->update([
                'status' => TenantStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => $reason,
            ]);

            $this->revokeTenantTokens($tenant);
        } else {
            $tenant->update([
                'status' => TenantStatus::Active,
                'suspended_at' => null,
                'suspension_reason' => null,
            ]);
        }

        CentralOverviewService::forget();

        return $tenant->refresh()->load('domains');
    }

    /**
     * @return list<string>
     */
    public function modules(Tenant $tenant): array
    {
        return $this->modules->codesForTenant((string) $tenant->id);
    }

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function syncModules(Tenant $tenant, array $codes): array
    {
        $before = $this->modules->codesForTenant((string) $tenant->id);
        $after = $this->modules->syncTenantModules($tenant, $codes);

        if ($before !== $after) {
            $this->auditWriter->write(
                event: 'modules_updated',
                auditable: $tenant,
                oldValues: ['modules' => $before],
                newValues: ['modules' => $after],
                tags: 'central,modules',
            );
        }

        CentralOverviewService::forget();

        return $after;
    }

    private function revokeTenantTokens(Tenant $tenant): void
    {
        try {
            $tenant->run(function (): void {
                DB::table('personal_access_tokens')->delete();
            });
        } catch (\Throwable) {
            // Missing schema: nothing to revoke; the status gate still blocks access.
        }
    }
}
