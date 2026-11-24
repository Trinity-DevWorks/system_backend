<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\CentralRole;
use App\Models\User;
use Illuminate\Support\Collection;

readonly class CentralUserResponseData
{
    /**
     * @param  array{id: int, name: string, is_system: bool}|null  $role
     */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $email,
        public bool $isActive,
        public ?int $centralRoleId,
        public ?array $role,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(User $user): self
    {
        $user->loadMissing('centralRole');
        $role = $user->centralRole;

        return new self(
            id: (string) $user->id,
            name: (string) $user->name,
            email: $user->email !== null ? (string) $user->email : null,
            isActive: (bool) $user->is_active,
            centralRoleId: $user->central_role_id !== null ? (int) $user->central_role_id : null,
            role: $role instanceof CentralRole
                ? ['id' => (int) $role->id, 'name' => (string) $role->name, 'is_system' => (bool) $role->is_system]
                : null,
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
            ->map(fn (User $u): array => self::fromModel($u)->toArray())
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
            'is_active' => $this->isActive,
            'central_role_id' => $this->centralRoleId,
            'role' => $this->role,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
