<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\CentralPermission;
use App\Models\Central\CentralRole;
use Illuminate\Support\Collection;

readonly class CentralRoleResponseData
{
    /**
     * @param  array<int, array<string, mixed>>|null  $permissions
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public bool $isActive,
        public bool $isSystem,
        public ?int $usersCount,
        public ?array $permissions,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(CentralRole $role, bool $withPermissions = false): self
    {
        $perms = null;
        if ($withPermissions) {
            $role->load(['permissions' => fn ($q) => $q->orderBy('resource_key')]);
            $perms = $role->permissions->map(fn (CentralPermission $p): array => [
                'permission_id' => (int) $p->id,
                'resource_key' => (string) $p->resource_key,
                'resource_label' => (string) $p->resource_label,
                'can_view' => (bool) $p->pivot->can_view,
                'can_add' => (bool) $p->pivot->can_add,
                'can_edit' => (bool) $p->pivot->can_edit,
                'can_delete' => (bool) $p->pivot->can_delete,
                'can_import' => (bool) $p->pivot->can_import,
                'can_export' => (bool) $p->pivot->can_export,
                'can_reverse' => (bool) $p->pivot->can_reverse,
            ])->values()->all();
        }

        $usersCount = $role->getAttribute('users_count');

        return new self(
            id: (int) $role->id,
            name: (string) $role->name,
            description: $role->description !== null ? (string) $role->description : null,
            isActive: (bool) $role->is_active,
            isSystem: (bool) $role->is_system,
            usersCount: $usersCount !== null ? (int) $usersCount : null,
            permissions: $perms,
            createdAt: (string) $role->created_at,
            updatedAt: (string) $role->updated_at,
        );
    }

    /**
     * @param  Collection<int, CentralRole>  $roles
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $roles): array
    {
        return $roles
            ->map(fn (CentralRole $r): array => self::fromModel($r)->toArray())
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
            'is_active' => $this->isActive,
            'is_system' => $this->isSystem,
            'users_count' => $this->usersCount,
            'permissions' => $this->permissions,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
