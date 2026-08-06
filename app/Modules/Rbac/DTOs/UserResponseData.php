<?php

declare(strict_types=1);

namespace App\Modules\Rbac\DTOs;

use App\Models\User;
use Illuminate\Support\Collection;

readonly class UserResponseData
{
    /**
     * @param  array<int, array{id: int, name: string}>  $branches
     * @param  array<int>  $branchIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public bool $active,
        public ?int $roleId,
        public ?string $roleName,
        public array $branches,
        public array $branchIds,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(User $user): self
    {
        $user->loadMissing(['role:id,name', 'branches:id,name']);

        $branches = $user->branches
            ->map(fn ($branch): array => [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
            ])
            ->values()
            ->all();

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            active: (bool) $user->active,
            roleId: $user->role_id,
            roleName: $user->role?->name,
            branches: $branches,
            branchIds: array_map('intval', $user->branches->pluck('id')->all()),
            createdAt: (string) $user->created_at,
            updatedAt: (string) $user->updated_at,
        );
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $users): array
    {
        return $users
            ->map(fn (User $user): array => self::fromModel($user)->toArray())
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
            'email' => $this->email,
            'active' => $this->active,
            'role' => $this->roleId !== null
                ? ['id' => $this->roleId, 'name' => $this->roleName]
                : null,
            'branches' => $this->branches,
            'branch_ids' => $this->branchIds,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
