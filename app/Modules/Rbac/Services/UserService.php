<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Services;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Notification\Services\DomainNotificationPublisher;
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
        private readonly PermissionService $permissionService,
        private readonly BranchContextService $branchContext,
        private readonly DomainNotificationPublisher $notifications,
    ) {}

    /**
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        $users = User::query()
            ->with([
                'branches' => fn ($q) => $q->select('branches.id', 'branches.name'),
                'avatarAttachment',
            ])
            ->orderBy('name')
            ->get();

        $this->eagerLoadBranchRolesForMany($users);

        return $users;
    }

    public function find(User $user): User
    {
        $user->load([
            'branches' => fn ($q) => $q->select('branches.id', 'branches.name'),
            'avatarAttachment',
        ]);
        $this->eagerLoadBranchRolesForMany(new Collection([$user]));

        return $user;
    }

    /**
     * @param  array{
     *   name: string,
     *   email: string,
     *   password: string,
     *   is_active: bool,
     *   phone?: string|null,
     *   branch_assignments: list<array{branch_id: int, role_id: int}>
     * }  $data
     */
    public function create(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'],
                'created_by' => auth()->id(),
            ]);

            $this->syncBranchAssignments($user, $data['branch_assignments']);

            $this->permissionService->invalidateCacheForUser($user->fresh() ?? $user);

            $created = $this->find($user);
            $this->notifications->userCreated($created);
            if ($data['branch_assignments'] !== []) {
                $this->notifications->userRoleAssigned($created, $data['branch_assignments']);
            }

            return $created;
        });
    }

    /**
     * @param  array{
     *   name: string,
     *   email: string,
     *   is_active: bool,
     *   password?: string|null,
     *   phone?: string|null,
     *   branch_assignments: list<array{branch_id: int, role_id: int}>
     * }  $data
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

            $this->assertOwnerProtection($user, $data['branch_assignments'], (bool) $data['is_active']);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
            ];

            if (array_key_exists('phone', $data)) {
                $payload['phone'] = $data['phone'];
            }

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

            $wasActive = (bool) $user->is_active;
            $assignmentsChanged = $this->assignmentsDiffer($user, $data['branch_assignments']);

            $user->update($payload);

            $this->syncBranchAssignments($user, $data['branch_assignments']);

            if ($assignmentsChanged) {
                $this->permissionService->invalidateCacheForUser($user->fresh() ?? $user);
            }

            if ($wasActive && ! $data['is_active']) {
                $user->tokens()->delete();
            }

            if (! empty($data['password'])) {
                $user->tokens()->delete();
            }

            $updated = $this->find($user->refresh());

            if ($wasActive && ! $data['is_active']) {
                $this->notifications->userDeactivated($updated);
            }

            if ($assignmentsChanged) {
                $this->notifications->userRoleAssigned($updated, $data['branch_assignments']);
            }

            return $updated;
        });
    }

    /**
     * Self-service profile update (no branch role / is_active changes).
     *
     * @param  array{
     *   name: string,
     *   phone?: string|null,
     *   current_password?: string|null,
     *   password?: string|null,
     *   preferred_branch_id?: int|null
     * }  $data
     */
    public function updateMe(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $payload = [
                'name' => $data['name'],
            ];

            if (array_key_exists('phone', $data)) {
                $payload['phone'] = $data['phone'];
            }

            if (array_key_exists('preferred_branch_id', $data)) {
                $preferredId = $data['preferred_branch_id'];
                if ($preferredId !== null) {
                    $preferredId = (int) $preferredId;
                    if (! $this->branchContext->canAccessBranch($preferredId, $user)) {
                        abort(422, 'You cannot prefer a branch you cannot access.', [
                            'X-Error-Code' => 'USER_PREFERRED_BRANCH_FORBIDDEN',
                        ]);
                    }
                }
                $payload['preferred_branch_id'] = $preferredId;
            }

            if (! empty($data['password'])) {
                $currentPassword = (string) ($data['current_password'] ?? '');
                if ($currentPassword === '' || ! Hash::check($currentPassword, (string) $user->getAuthPassword())) {
                    throw new HttpResponseException(
                        ApiResponse::error(
                            'The current password is incorrect.',
                            422,
                            null,
                            ['current_password' => ['The current password is incorrect.']],
                            null,
                            null,
                            'CURRENT_PASSWORD_INVALID'
                        )
                    );
                }
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

            $user->update($payload);

            if (! empty($data['password'])) {
                $user->tokens()->delete();
            }

            return $this->find($user->refresh());
        });
    }

    /**
     * Assign a role to one branch, or to every assigned branch when branch_id is omitted.
     */
    public function assignRole(User $user, int $roleId, ?int $branchId = null): User
    {
        $result = DB::transaction(function () use ($user, $roleId, $branchId): array {
            $user->loadMissing('branches');

            if ($branchId !== null) {
                if (! $user->branches->contains(fn ($b): bool => (int) $b->id === $branchId)) {
                    abort(422, 'User is not assigned to this branch.', [
                        'X-Error-Code' => 'USER_BRANCH_NOT_ASSIGNED',
                    ]);
                }

                $assignments = $user->branches
                    ->map(fn ($b): array => [
                        'branch_id' => (int) $b->id,
                        'role_id' => (int) $b->id === $branchId
                            ? $roleId
                            : (int) $b->pivot->role_id,
                    ])
                    ->values()
                    ->all();
            } else {
                if ($user->branches->isEmpty()) {
                    abort(422, 'Each user must be assigned to at least one branch.', [
                        'X-Error-Code' => 'USER_BRANCH_REQUIRED',
                    ]);
                }

                $assignments = $user->branches
                    ->map(fn ($b): array => [
                        'branch_id' => (int) $b->id,
                        'role_id' => $roleId,
                    ])
                    ->values()
                    ->all();
            }

            $this->assertOwnerProtection($user, $assignments, (bool) $user->is_active);

            $changed = $this->assignmentsDiffer($user, $assignments);
            $this->syncBranchAssignments($user, $assignments);

            if ($changed) {
                $this->permissionService->invalidateCacheForUser($user->fresh() ?? $user);
            }

            return [
                'user' => $this->find($user->refresh()),
                'changed' => $changed,
                'assignments' => $assignments,
            ];
        });

        if ($result['changed']) {
            $this->notifications->userRoleAssigned($result['user'], $result['assignments']);
        }

        return $result['user'];
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
            // Soft-delete: preserves historical attribution FKs (created_by, posted_by, ...).
            $user->delete();
        });
    }

    /**
     * @param  list<array{branch_id: int, role_id: int}>|null  $nextAssignments
     */
    private function assertOwnerProtection(
        User $user,
        ?array $nextAssignments,
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

        $stillOwner = false;
        if ($nextAssignments !== null) {
            foreach ($nextAssignments as $row) {
                if ((int) $row['role_id'] === $ownerRoleId) {
                    $stillOwner = true;

                    break;
                }
            }
        }

        $demotingOwner = ! $stillOwner;
        $deactivating = ! $nextActive;

        if (($demotingOwner || $deactivating) && $remainingOwners < 1) {
            abort(409, 'Cannot change role or deactivate the last active owner account.', ['X-Error-Code' => 'USER_LAST_OWNER_PROTECTED']);
        }
    }

    private function isOwner(User $user): bool
    {
        $ownerRoleId = $this->ownerRoleId();
        if ($ownerRoleId === null) {
            return false;
        }

        $user->loadMissing('branches');

        return $user->branches->contains(
            fn ($branch): bool => (int) ($branch->pivot?->role_id ?? 0) === $ownerRoleId
        );
    }

    private function ownerRoleId(): ?int
    {
        $id = Role::query()->where('name', self::OWNER_ROLE_NAME)->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function activeOwnerCountExcluding(string $excludeUserId): int
    {
        $ownerRoleId = $this->ownerRoleId();
        if ($ownerRoleId === null) {
            return 0;
        }

        return (int) DB::table('users')
            ->where('users.is_active', true)
            ->where('users.id', '!=', $excludeUserId)
            ->whereExists(function ($query) use ($ownerRoleId): void {
                $query->selectRaw('1')
                    ->from('branch_user')
                    ->whereColumn('branch_user.user_id', 'users.id')
                    ->where('branch_user.role_id', $ownerRoleId);
            })
            ->count();
    }

    /**
     * @param  list<array{branch_id: int, role_id: int}>  $assignments
     */
    private function syncBranchAssignments(User $user, array $assignments): void
    {
        $normalized = $this->normalizeAssignments($assignments);

        if ($normalized === []) {
            abort(422, 'Each user must be assigned to at least one branch.', [
                'X-Error-Code' => 'USER_BRANCH_REQUIRED',
            ]);
        }

        $sync = [];
        foreach ($normalized as $row) {
            $sync[$row['branch_id']] = ['role_id' => $row['role_id']];
        }

        $user->branches()->sync($sync);
        $user->unsetRelation('branches');
        $this->branchContext->clearPreferredBranchIfInaccessible($user->refresh());
    }

    /**
     * @param  list<array{branch_id: int, role_id: int}>  $assignments
     * @return list<array{branch_id: int, role_id: int}>
     */
    private function normalizeAssignments(array $assignments): array
    {
        $byBranch = [];
        foreach ($assignments as $row) {
            $branchId = (int) ($row['branch_id'] ?? 0);
            $roleId = (int) ($row['role_id'] ?? 0);
            if ($branchId < 1 || $roleId < 1) {
                continue;
            }
            $byBranch[$branchId] = [
                'branch_id' => $branchId,
                'role_id' => $roleId,
            ];
        }

        return array_values($byBranch);
    }

    /**
     * @param  list<array{branch_id: int, role_id: int}>  $assignments
     */
    private function assignmentsDiffer(User $user, array $assignments): bool
    {
        $user->loadMissing('branches');
        $current = [];
        foreach ($user->branches as $branch) {
            $current[(int) $branch->id] = (int) $branch->pivot->role_id;
        }

        $next = [];
        foreach ($this->normalizeAssignments($assignments) as $row) {
            $next[$row['branch_id']] = $row['role_id'];
        }

        ksort($current);
        ksort($next);

        return $current !== $next;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function eagerLoadBranchRolesForMany(Collection $users): void
    {
        $roleIds = [];
        foreach ($users as $user) {
            foreach ($user->branches as $branch) {
                $roleId = (int) ($branch->pivot->role_id ?? 0);
                if ($roleId > 0) {
                    $roleIds[$roleId] = $roleId;
                }
            }
        }

        if ($roleIds === []) {
            return;
        }

        $roles = Role::query()
            ->whereIn('id', array_values($roleIds))
            ->get(['id', 'name'])
            ->keyBy('id');

        foreach ($users as $user) {
            foreach ($user->branches as $branch) {
                $roleId = (int) ($branch->pivot->role_id ?? 0);
                $branch->setRelation('assignedRole', $roles->get($roleId));
            }
        }
    }
}
