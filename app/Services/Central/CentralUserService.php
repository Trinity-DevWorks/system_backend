<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Http\Responses\ApiResponse;
use App\Models\Central\CentralRole;
use App\Models\User;
use App\Services\CentralPermissionService;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Central (platform) users. Mirrors the tenant UserService without branches:
 * each user has one `central_role_id`.
 */
final class CentralUserService
{
    public function __construct(
        private readonly CentralPermissionService $permissionService,
    ) {}

    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = User::query()->with('centralRole')->orderBy('name');
        ListPagination::applySearch($query, $search, ['name', 'email']);

        return $query->paginate($perPage);
    }

    /**
     * @return Collection<int, User>
     */
    public function names(): Collection
    {
        return User::query()->select(['id', 'name', 'email'])->orderBy('name')->get();
    }

    /**
     * @param  array{name: string, email: string, password: string, is_active: bool, central_role_id: int}  $data
     */
    public function create(array $data): User
    {
        return $this->transaction(function () use ($data): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'],
                'central_role_id' => (int) $data['central_role_id'],
            ]);

            $this->permissionService->invalidateCacheForUser($user);

            return $user->load('centralRole');
        });
    }

    /**
     * @param  array{name: string, email: string, is_active: bool, central_role_id: int, password?: string|null}  $data
     */
    public function update(User $user, array $data): User
    {
        return $this->transaction(function () use ($user, $data): User {
            $actor = auth()->user();
            if ($actor instanceof User && $actor->id === $user->id && ! $data['is_active']) {
                abort(422, 'You cannot deactivate your own account.', ['X-Error-Code' => 'USER_SELF_DEACTIVATE_FORBIDDEN']);
            }

            $nextRoleId = (int) $data['central_role_id'];
            $this->assertSuperAdminProtection($user, $nextRoleId, (bool) $data['is_active']);

            $payload = [
                'name' => $data['name'],
                'email' => $data['email'],
                'is_active' => $data['is_active'],
                'central_role_id' => $nextRoleId,
            ];

            if (! empty($data['password'])) {
                if (Hash::check((string) $data['password'], (string) $user->getAuthPassword())) {
                    throw new HttpResponseException(ApiResponse::error(
                        'The new password must be different from your current password.',
                        422,
                        null,
                        ['password' => ['The new password must be different from your current password.']],
                        null,
                        null,
                        'PASSWORD_UNCHANGED'
                    ));
                }
                $payload['password'] = $data['password'];
            }

            $wasActive = (bool) $user->is_active;
            $roleChanged = (int) ($user->central_role_id ?? 0) !== $nextRoleId;

            $user->update($payload);

            if ($roleChanged) {
                $this->permissionService->invalidateCacheForUser($user);
            }

            if (($wasActive && ! $data['is_active']) || ! empty($data['password'])) {
                $user->tokens()->delete();
            }

            return $user->refresh()->load('centralRole');
        });
    }

    public function delete(User $user): void
    {
        $actor = auth()->user();
        if ($actor instanceof User && $actor->id === $user->id) {
            abort(422, 'You cannot delete your own account.', ['X-Error-Code' => 'USER_SELF_DELETE_FORBIDDEN']);
        }

        $this->assertSuperAdminProtection($user, null, false);

        $this->transaction(function () use ($user): void {
            $user->tokens()->delete();
            // Soft-delete: preserves historical attribution FKs (created_by, audits).
            $user->delete();
        });
    }

    /**
     * The platform must keep at least one active Super Admin.
     */
    private function assertSuperAdminProtection(User $user, ?int $nextRoleId, bool $nextActive): void
    {
        $superAdminId = $this->superAdminRoleId();
        if ($superAdminId === null || (int) ($user->central_role_id ?? 0) !== $superAdminId || ! $user->is_active) {
            return;
        }

        $stillSuperAdmin = $nextRoleId === $superAdminId && $nextActive;
        if ($stillSuperAdmin) {
            return;
        }

        $remaining = User::query()
            ->where('is_active', true)
            ->where('central_role_id', $superAdminId)
            ->where('id', '!=', $user->id)
            ->count();

        if ($remaining < 1) {
            abort(409, 'The platform must keep at least one active Super Admin.', [
                'X-Error-Code' => 'CENTRAL_LAST_SUPER_ADMIN_PROTECTED',
            ]);
        }
    }

    private function superAdminRoleId(): ?int
    {
        $id = CentralRole::query()
            ->where('is_system', true)
            ->where('name', (string) config('central_rbac.super_admin_role', 'Super Admin'))
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function transaction(callable $callback): mixed
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction($callback);
    }
}
