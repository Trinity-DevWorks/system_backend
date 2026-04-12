<?php

namespace App\Modules\Rbac\DTOs;

use App\Modules\Rbac\Models\Permission;
use Illuminate\Support\Collection;

readonly class PermissionCatalogResponseData
{
    /**
     * @return array<int, array{id:int,resource_key:string,resource_label:string,created_at:string,updated_at:string}>
     */
    public static function collectionToArray(Collection $permissions): array
    {
        return $permissions
            ->map(fn (Permission $p): array => [
                'id' => $p->id,
                'resource_key' => $p->resource_key,
                'resource_label' => $p->resource_label,
                'created_at' => (string) $p->created_at,
                'updated_at' => (string) $p->updated_at,
            ])
            ->values()
            ->all();
    }
}
