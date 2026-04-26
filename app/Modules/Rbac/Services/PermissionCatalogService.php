<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Services;

use App\Modules\Rbac\Models\Permission;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;

final class PermissionCatalogService
{
    private const CACHE_KEY = 'rbac.permissions.catalog';

    public function allOrdered(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_KEY,
            Permission::class,
            fn (): Collection => Permission::query()->orderBy('resource_key')->get()
        );
    }

    public function forget(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
