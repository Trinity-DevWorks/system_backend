<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\CentralPermission;
use App\Support\CentralRbacResourceCatalog;
use Illuminate\Support\Collection;

readonly class CentralPermissionCatalogResponseData
{
    /**
     * @param  Collection<int, CentralPermission>  $permissions
     * @return array<int, array{id:int,resource_key:string,resource_label:string,actions:list<string>,created_at:string,updated_at:string}>
     */
    public static function collectionToArray(Collection $permissions): array
    {
        return $permissions
            ->map(fn (CentralPermission $p): array => [
                'id' => (int) $p->id,
                'resource_key' => (string) $p->resource_key,
                'resource_label' => (string) $p->resource_label,
                'actions' => CentralRbacResourceCatalog::actions((string) $p->resource_key),
                'created_at' => (string) $p->created_at,
                'updated_at' => (string) $p->updated_at,
            ])
            ->values()
            ->all();
    }
}
