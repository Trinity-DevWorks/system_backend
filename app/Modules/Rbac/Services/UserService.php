<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Services;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Rbac\Models\Role;
use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserService
{
    private const OWNER_ROLE_NAME = 'Owner';

    public function __construct(
        private readonly PermissionService $permissionService
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()
            ->with('role:id,name')
            ->orderBy('name')
            ->get();
    }

    public function find(User $user): User
    {
        $user->loadMissing('role:id,name');

        return $user;
    }

    /**
     * @param  array{name: string, email: string, password: string, is_active: bool, role_id: int}  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'],
                'role_id' => $data['role_id'],
                'created_by' => auth()->id(),
            ]);

            $this->permissionService->invalidateCacheForUser($user->fresh());

            return $this->find($user);
        });
    }

    /**
     * @param  array{name: string, email: string, is_active: bool, role_id: int, password?: string|null}  $data
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $actor = auth()->user();
            if ($actor instanceof User && $actor->id === $user->id) {
                if (! $data['is_active']) {
                    abort(422, 'You cannot deactivate your own account.', ['X-Error-Code' => 'USER_SELF_DEACTIVATE_FORBIDDEN']);
                }
            }

            $this->assertOwnerProtection($user, (int) $data['role_id'], (bool) $data['is_active']);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
                'role_id' => $data['role_id'],
            ];

            if (! empty($data['password'])) {
                if (Hash::check((string) $data['password'], (string) $user->getAuthPassword())) {
                    throw new HttpResponseException(
                        ApiResponse::error(
                            'The new password must be different from your current password.',
                            422,
                            null,
                            ['password' => ['The new password must be different from your current password.']],
                            null,
                            null,
                            'PASSWORD_UNCHANGED'
                        )
                    );
                }

                $payload['password'] = $data['password'];
            }

            $roleChanged = (int) $user->role_id !== (int) $data['role_id'];
            $wasActive = (bool) $user->is_active;

            $user->update($payload);

            if ($roleChanged) {
                $this->permissionService->invalidateCacheForUser($user->fresh());
            }

            if ($wasActive && ! $data['is_active']) {
                $user->tokens()->delete();
            }

            if (! empty($data['password'])) {
                $user->tokens()->delete();
            }

            return $this->find($user->refresh());
        });
    }

    public function assignRole(User $user, int $roleId): User
    {
        return DB::transaction(function () use ($user, $roleId): User {
            $this->assertOwnerProtection($user, $roleId, (bool) $user->is_active);

            $roleChanged = (int) $user->role_id !== $roleId;
            $user->update(['role_id' => $roleId]);

            if ($roleChanged) {
                $this->permissionService->invalidateCacheForUser($user->fresh());
            }

            return $this->find($user->refresh());
        });
    }

    public function delete(User $user): void
    {
        $actor = auth()->user();
        if ($actor instanceof User && $actor->id === $user->id) {
            abort(422, 'You cannot delete your own account.', ['X-Error-Code' => 'USER_SELF_DELETE_FORBIDDEN']);
        }

        $this->assertOwnerProtection($user, null, false, deleting: true);

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            // Soft-delete: preserves historical attribution FKs (created_by, posted_by, …).
            $user->delete();
        });
    }

    private function assertOwnerProtection(
        User $user,
        ?int $nextRoleId,
        bool $nextActive,
        bool $deleting = false
    ): void {
        if (! $this->isOwner($user)) {
            return;
        }

        $ownerRoleId = $this->ownerRoleId();
        if ($ownerRoleId === null) {
            return;
        }

        $remainingOwners = $this->activeOwnerCountExcluding($user->id);

        if ($deleting) {
            if ($remainingOwners < 1) {
                abort(409, 'Cannot delete the last active owner account.', ['X-Error-Code' => 'USER_LAST_OWNER_PROTECTED']);
            }

            return;
        }

        $demotingOwner = $nextRoleId !== null && $nextRoleId !== $ownerRoleId;
        $deactivating = ! $nextActive;

        if (($demotingOwner || $deactivating) && $remainingOwners < 1) {
            abort(409, 'Cannot change role or deactivate the last active owner account.', ['X-Error-Code' => 'USER_LAST_OWNER_PROTECTED']);
        }
    }

    private function isOwner(User $user): bool
    {
        $user->loadMissing('role:id,name');

        return $user->role?->name === self::OWNER_ROLE_NAME;
    }

    private function ownerRoleId(): ?int
    {
        return Role::query()->where('name', self::OWNER_ROLE_NAME)->value('id');
    }

    private function activeOwnerCountExcluding(string $excludeUserId): int
    {
        $ownerRoleId = $this->ownerRoleId();
        if ($ownerRoleId === null) {
            return 0;
        }

        return User::query()
            ->where('role_id', $ownerRoleId)
            ->where('is_active', true)
            ->where('id', '!=', $excludeUserId)
            ->count();
    }
}
