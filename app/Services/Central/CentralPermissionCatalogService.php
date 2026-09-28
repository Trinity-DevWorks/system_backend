<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Models\Central\CentralPermission;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

final class CentralPermissionCatalogService
{
    private const CACHE_KEY = 'central.rbac.permissions.catalog';

    /**
     * @return Collection<int, CentralPermission>
     */
    public function allOrdered(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_KEY,
            CentralPermission::class,
            fn (): Collection => CentralPermission::query()->orderBy('resource_key')->get()
        );
    }

    public function forget(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
