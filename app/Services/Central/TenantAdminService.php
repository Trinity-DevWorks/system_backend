<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Enums\TenantStatus;
use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditWriter;
use App\Services\ModuleEntitlementService;
use App\Support\ListPagination;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Central tenant administration: list, detail, rename, suspend/activate, modules, delete.
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

    /**
     * Permanently delete a suspended tenant: its stored files, schema, domains, and module rows.
     */
    public function delete(Tenant $tenant): void
    {
        if (! $tenant->isSuspended()) {
            abort(409, 'Suspend the tenant before deleting it.', ['X-Error-Code' => 'TENANT_DELETE_REQUIRES_SUSPENSION']);
        }

        $tenantId = (string) $tenant->id;
        $snapshot = [
            'id' => $tenantId,
            'name' => $tenant->name,
            'domains' => $tenant->domains->pluck('domain')->values()->all(),
            'modules' => $this->modules->codesForTenant($tenantId),
            'suspended_at' => $tenant->suspended_at !== null ? (string) $tenant->suspended_at : null,
            'suspension_reason' => $tenant->suspension_reason,
        ];

        $this->deleteTenantFiles($tenant);

        // The explicit audit below carries domains and modules, which the model audit would miss.
        Tenant::withoutAuditing(fn () => $tenant->delete());

        $this->auditWriter->write(
            event: 'deleted',
            auditable: $tenant,
            oldValues: $snapshot,
            tags: 'central,tenants',
        );

        TenantReferenceCache::forgetForTenant($tenantId, ModuleEntitlementService::CACHE_KEY);
        CentralOverviewService::forget();
    }

    /**
     * Local disks share one root across tenants, so remove each attachment file
     * instead of a per-tenant directory.
     */
    private function deleteTenantFiles(Tenant $tenant): void
    {
        try {
            $tenant->run(function (): void {
                Attachment::withTrashed()
                    ->select(['id', 'disk', 'file_path'])
                    ->chunkById(200, function (Collection $attachments): void {
                        foreach ($attachments as $attachment) {
                            $path = (string) $attachment->file_path;
                            if ($path === '') {
                                continue;
                            }

                            try {
                                Storage::disk((string) $attachment->disk)->delete($path);
                            } catch (\Throwable) {
                                // Missing disk or file: nothing left to remove.
                            }
                        }
                    });
            });
        } catch (\Throwable) {
            // Missing schema: no attachment rows to clean up.
        }
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
