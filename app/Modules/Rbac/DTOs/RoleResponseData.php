<?php

namespace App\Modules\Rbac\DTOs;

use App\Modules\Rbac\Models\Role;
use Illuminate\Support\Collection;

readonly class RoleResponseData
{
    /**
     * @param  array<int, array<string, mixed>>|null  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public bool $active,
        public ?array $permissions,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Role $role, bool $withPermissions = false): self
    {
        $perms = null;
        if ($withPermissions) {
            $role->load(['permissions' => fn ($q) => $q->orderBy('resource_key')]);
            $perms = $role->permissions->map(function ($p): array {
                return [
                    'permission_id' => $p->id,
                    'resource_key' => $p->resource_key,
                    'resource_label' => $p->resource_label,
                    'can_view' => (bool) $p->pivot->can_view,
                    'can_add' => (bool) $p->pivot->can_add,
                    'can_edit' => (bool) $p->pivot->can_edit,
                    'can_delete' => (bool) $p->pivot->can_delete,
                    'can_import' => (bool) $p->pivot->can_import,
                    'can_export' => (bool) $p->pivot->can_export,
                ];
            })->values()->all();
        }

        return new self(
            id: $role->id,
            name: $role->name,
            description: $role->description,
            active: (bool) $role->active,
            permissions: $perms,
            createdAt: (string) $role->created_at,
            updatedAt: (string) $role->updated_at,
        );
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $roles): array
    {
        return $roles
            ->map(fn (Role $r): array => self::fromModel($r)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'active' => $this->active,
            'permissions' => $this->permissions,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
