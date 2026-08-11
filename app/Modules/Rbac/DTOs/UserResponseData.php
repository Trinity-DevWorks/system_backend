<?php

declare(strict_types=1);

namespace App\Modules\Rbac\DTOs;

use App\Models\Attachment;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Rbac\Models\Role;
use Illuminate\Support\Collection;

readonly class UserResponseData
{
    /**
     * @param  array<int, array{id: int, name: string, role_id: int|null, role: array{id: int, name: string}|null}>  $branches
     * @param  array<int>  $branchIds
     * @param  list<array{branch_id: int, role_id: int}>  $branchAssignments
     * @param  array{id: string, file_name: string, mime_type: string}|null  $avatar
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $email,
        public ?string $phone,
        public bool $isActive,
        public ?int $preferredBranchId,
        public ?int $roleId,
        public ?string $roleName,
        public array $branches,
        public array $branchIds,
        public array $branchAssignments,
        public ?array $avatar,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(User $user): self
    {
        $user->loadMissing([
            'branches' => fn ($q) => $q->select('branches.id', 'branches.name'),
            'avatarAttachment',
        ]);

        $branches = $user->branches
            ->map(function (Branch $branch): array {
                $roleId = $branch->pivot?->role_id !== null ? (int) $branch->pivot->role_id : null;
                $assignedRole = $branch->relationLoaded('assignedRole') ? $branch->getRelation('assignedRole') : null;

                return [
                    'id' => (int) $branch->id,
                    'name' => (string) $branch->name,
                    'role_id' => $roleId,
                    'role' => $roleId !== null && $assignedRole !== null
                        ? ['id' => (int) $assignedRole->id, 'name' => (string) $assignedRole->name]
                        : ($roleId !== null ? ['id' => $roleId, 'name' => null] : null),
                ];
            })
            ->values()
            ->all();

        // Fill role names if assignedRole was not eager-loaded.
        $missingRoleIds = collect($branches)
            ->filter(fn (array $b): bool => ($b['role']['id'] ?? null) !== null && ($b['role']['name'] ?? null) === null)
            ->map(fn (array $b): int => (int) $b['role']['id'])
            ->unique()
            ->values()
            ->all();

        if ($missingRoleIds !== []) {
            $names = Role::query()
                ->whereIn('id', $missingRoleIds)
                ->pluck('name', 'id');

            $branches = array_map(static function (array $b) use ($names): array {
                if (($b['role']['id'] ?? null) !== null && ($b['role']['name'] ?? null) === null) {
                    $b['role']['name'] = $names[(int) $b['role']['id']] ?? null;
                }

                return $b;
            }, $branches);
        }

        $assignments = array_map(
            static fn (array $b): array => [
                'branch_id' => (int) $b['id'],
                'role_id' => (int) $b['role_id'],
            ],
            array_values(array_filter($branches, static fn (array $b): bool => $b['role_id'] !== null))
        );

        $uniqueRoleIds = collect($assignments)->pluck('role_id')->unique()->values();
        $sharedRoleId = $uniqueRoleIds->count() === 1 ? (int) $uniqueRoleIds->first() : null;
        $sharedRoleName = null;
        if ($sharedRoleId !== null) {
            foreach ($branches as $branch) {
                if ((int) ($branch['role_id'] ?? 0) === $sharedRoleId) {
                    $sharedRoleName = $branch['role']['name'] ?? null;

                    break;
                }
            }
        }

        $preferredBranchId = $user->preferred_branch_id !== null
            ? (int) $user->preferred_branch_id
            : null;

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            phone: $user->phone !== null && $user->phone !== '' ? (string) $user->phone : null,
            isActive: (bool) $user->is_active,
            preferredBranchId: $preferredBranchId,
            roleId: $sharedRoleId,
            roleName: $sharedRoleName,
            branches: $branches,
            branchIds: array_map(static fn (array $b): int => (int) $b['id'], $branches),
            branchAssignments: $assignments,
            avatar: self::avatarBrief($user->avatarAttachment),
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
     * @return array{id: string, file_name: string, mime_type: string}|null
     */
    public static function avatarBrief(?Attachment $attachment): ?array
    {
        if ($attachment === null) {
            return null;
        }

        return [
            'id' => (string) $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
        ];
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
            'phone' => $this->phone,
            'is_active' => $this->isActive,
            'preferred_branch_id' => $this->preferredBranchId,
            'role' => $this->roleId !== null
                ? ['id' => $this->roleId, 'name' => $this->roleName]
                : null,
            'branches' => $this->branches,
            'branch_ids' => $this->branchIds,
            'branch_assignments' => $this->branchAssignments,
            'avatar' => $this->avatar,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
