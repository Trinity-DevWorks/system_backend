<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Module;
use App\Models\Tenant;
use App\Models\TenantModule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ModuleEntitlementService
{
    /** @var array<string, list<string>> */
    private array $codesByTenant = [];

    /**
     * Sync catalog rows from config/modules.php into the modules table.
     */
    public function syncCatalog(): void
    {
        foreach (config('modules.catalog', []) as $code => $meta) {
            Module::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $meta['name'],
                    'description' => $meta['description'] ?? null,
                    'is_core' => (bool) ($meta['is_core'] ?? false),
                    'sort_order' => (int) ($meta['sort_order'] ?? 0),
                ]
            );
        }
    }

    /**
     * @return list<string>
     */
    public function codesForTenant(string $tenantId): array
    {
        if (isset($this->codesByTenant[$tenantId])) {
            return $this->codesByTenant[$tenantId];
        }

        $codes = TenantModule::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('module_code')
            ->pluck('module_code')
            ->map(fn ($code) => (string) $code)
            ->values()
            ->all();

        if (! in_array('core', $codes, true)) {
            $codes[] = 'core';
            sort($codes);
        }

        return $this->codesByTenant[$tenantId] = $codes;
    }

    public function tenantHas(string $tenantId, string $moduleCode): bool
    {
        if ($moduleCode === 'core') {
            return true;
        }

        return in_array($moduleCode, $this->codesForTenant($tenantId), true);
    }

    /**
     * Assign default modules for a newly created tenant.
     */
    public function assignDefaults(Tenant $tenant): void
    {
        $defaults = array_values(array_unique(array_merge(
            ['core'],
            config('modules.default_on_create', ['core'])
        )));

        $this->syncTenantModules($tenant, $defaults);
    }

    /**
     * Replace tenant module assignments. Core is always kept.
     *
     * @param  list<string>  $moduleCodes
     * @return list<string>
     */
    public function syncTenantModules(Tenant $tenant, array $moduleCodes): array
    {
        $validCodes = Module::query()->pluck('code')->all();
        $requested = array_values(array_unique(array_map('strval', $moduleCodes)));

        $unknown = array_values(array_diff($requested, $validCodes));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'modules' => ['Unknown module code(s): '.implode(', ', $unknown)],
            ]);
        }

        if (! in_array('core', $requested, true)) {
            $requested[] = 'core';
        }

        DB::connection(config('tenancy.database.central_connection'))->transaction(function () use ($tenant, $requested): void {
            TenantModule::query()->where('tenant_id', $tenant->id)->delete();

            $now = now();
            $rows = array_map(fn (string $code) => [
                'tenant_id' => $tenant->id,
                'module_code' => $code,
                'created_at' => $now,
                'updated_at' => $now,
            ], $requested);

            TenantModule::query()->insert($rows);
        });

        unset($this->codesByTenant[$tenant->id]);

        return $this->codesForTenant($tenant->id);
    }

    /**
     * @return Collection<int, Module>
     */
    public function catalog(): Collection
    {
        return Module::query()->orderBy('sort_order')->orderBy('code')->get();
    }

    /**
     * Grant every catalog module to a tenant (dev migration helper).
     */
    public function grantAll(Tenant $tenant): void
    {
        $this->syncTenantModules(
            $tenant,
            Module::query()->pluck('code')->all()
        );
    }
}
