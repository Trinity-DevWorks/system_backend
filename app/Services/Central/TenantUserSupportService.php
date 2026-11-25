<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Models\Audit;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\Rbac\Models\Role;
use App\Notifications\ResetPasswordNotification;
use App\Services\AuditWriter;
use App\Services\PermissionService;
use App\Support\FrontendUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * Central support for a tenant's users: list them, send a password-reset link, and move ownership.
 * Central never sets or sees a tenant password; the reset link is emailed and also handed to the
 * central admin once (for when the tenant's email does not arrive).
 *
 * Tenant data is read and written inside `$tenant->run()`; the audit rows go to the central log.
 */
final class TenantUserSupportService
{
    private const OWNER_ROLE = 'Owner';

    private const ADMIN_ROLE = 'Admin';

    public function __construct(
        private readonly AuditWriter $auditWriter,
    ) {}

    /**
     * @return list<array{id: string, name: string, email: string, is_active: bool, is_owner: bool, roles: list<string>, last_login_at: string|null, created_at: string|null}>
     */
    public function list(Tenant $tenant): array
    {
        return $tenant->run(function (): array {
            $roleNames = DB::table('branch_user')
                ->join('roles', 'roles.id', '=', 'branch_user.role_id')
                ->select(['branch_user.user_id', 'roles.name'])
                ->get()
                ->groupBy('user_id')
                ->map(fn ($rows): array => $rows->pluck('name')->map(fn ($n): string => (string) $n)->unique()->sort()->values()->all());

            // Last login = newest `login` audit per user (no extra column; audits are kept for years).
            $lastLogins = Audit::query()
                ->where('event', 'login')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->selectRaw('user_id, max(created_at) as last_login_at')
                ->pluck('last_login_at', 'user_id');

            return User::query()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_active', 'created_at'])
                ->map(function (User $user) use ($roleNames, $lastLogins): array {
                    $roles = $roleNames->get((string) $user->id, []);
                    $lastLogin = $lastLogins->get((string) $user->id);

                    return [
                        'id' => (string) $user->id,
                        'name' => (string) $user->name,
                        'email' => (string) $user->email,
                        'is_active' => (bool) $user->is_active,
                        'is_owner' => in_array(self::OWNER_ROLE, $roles, true),
                        'roles' => $roles,
                        'last_login_at' => $lastLogin !== null ? Carbon::parse((string) $lastLogin)->toIso8601String() : null,
                        'created_at' => $user->created_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * Creates a reset token, emails the link to the user, and returns the link for the central admin.
     *
     * @return array{email: string, url: string, expires_at: string, email_sent: bool}
     */
    public function sendResetLink(Tenant $tenant, string $userId): array
    {
        $domain = $tenant->domains->first();
        $host = $domain instanceof Domain ? (string) $domain->domain : '';
        if ($host === '') {
            abort(422, 'The tenant has no domain to send a reset link for.', ['X-Error-Code' => 'TENANT_NO_DOMAIN']);
        }

        $result = $tenant->run(function () use ($userId, $host): array {
            $user = $this->findUser($userId);
            if (! $user->is_active) {
                abort(422, 'This user is inactive; ask the tenant admin to activate it first.', ['X-Error-Code' => 'TENANT_USER_INACTIVE']);
            }

            $token = Password::broker()->createToken($user);
            $email = (string) $user->getEmailForPasswordReset();
            $url = FrontendUrl::passwordResetForHost($host, $token, $email);

            $emailSent = true;

            try {
                $user->notify(new ResetPasswordNotification($token, $url));
            } catch (\Throwable $e) {
                // The link is still returned to the central admin, so a mail outage is not fatal.
                $emailSent = false;
                Log::warning('Central password reset email failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            }

            return ['user_id' => (string) $user->id, 'email' => $email, 'url' => $url, 'email_sent' => $emailSent];
        });

        $expireMinutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        // Never log the token or link: only who it was for.
        $this->auditWriter->write(
            event: 'password_reset_link_created',
            auditable: $tenant,
            newValues: ['user_id' => $result['user_id'], 'email' => $result['email'], 'email_sent' => $result['email_sent']],
            tags: 'central,tenant_users,security',
        );

        return [
            'email' => $result['email'],
            'url' => $result['url'],
            'expires_at' => now()->addMinutes($expireMinutes)->toIso8601String(),
            'email_sent' => $result['email_sent'],
        ];
    }

    /**
     * Gives the user the Owner role on the default branch. With `$demoteOthers`, every other
     * owner's Owner rows become Admin. At least one active owner always remains.
     */
    public function makeOwner(Tenant $tenant, string $userId, bool $demoteOthers): void
    {
        $change = $tenant->run(function () use ($userId, $demoteOthers): array {
            return DB::transaction(function () use ($userId, $demoteOthers): array {
                $user = $this->findUser($userId);
                if (! $user->is_active) {
                    abort(422, 'Only an active user can become the owner.', ['X-Error-Code' => 'TENANT_USER_INACTIVE']);
                }

                $ownerRoleId = (int) Role::query()->where('name', self::OWNER_ROLE)->value('id');
                $adminRoleId = (int) Role::query()->where('name', self::ADMIN_ROLE)->value('id');
                if ($ownerRoleId === 0 || ($demoteOthers && $adminRoleId === 0)) {
                    abort(409, 'The tenant roles are not set up.', ['X-Error-Code' => 'TENANT_ROLES_MISSING']);
                }

                $previousOwners = User::query()
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('branch_user')
                        ->whereColumn('branch_user.user_id', 'users.id')
                        ->where('branch_user.role_id', $ownerRoleId))
                    ->get(['id', 'email'])
                    ->mapWithKeys(fn (User $u): array => [(string) $u->id => (string) $u->email])
                    ->all();

                // Direct pivot write: BranchService::assignUserToDefaultBranch() would also send an
                // in-app notification as the signed-in (central) user, who does not exist here.
                $user->branches()->syncWithoutDetaching([
                    app(BranchService::class)->defaultBranchId() => ['role_id' => $ownerRoleId],
                ]);

                $demoted = [];
                if ($demoteOthers) {
                    foreach (array_keys($previousOwners) as $otherId) {
                        if ($otherId === (string) $user->id) {
                            continue;
                        }
                        DB::table('branch_user')
                            ->where('user_id', $otherId)
                            ->where('role_id', $ownerRoleId)
                            ->update(['role_id' => $adminRoleId, 'updated_at' => now()]);
                        $demoted[] = $previousOwners[$otherId];
                    }
                }

                $activeOwners = User::query()
                    ->where('is_active', true)
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('branch_user')
                        ->whereColumn('branch_user.user_id', 'users.id')
                        ->where('branch_user.role_id', $ownerRoleId))
                    ->count();
                if ($activeOwners < 1) {
                    abort(409, 'The tenant must keep at least one active owner.', ['X-Error-Code' => 'TENANT_LAST_OWNER_PROTECTED']);
                }

                app(PermissionService::class)->invalidateCacheForAllUsers();

                return [
                    'previous_owners' => array_values($previousOwners),
                    'new_owner' => (string) $user->email,
                    'demoted' => $demoted,
                ];
            });
        });

        $this->auditWriter->write(
            event: 'owner_changed',
            auditable: $tenant,
            oldValues: ['owners' => $change['previous_owners']],
            newValues: ['new_owner' => $change['new_owner'], 'demoted_to_admin' => $change['demoted']],
            tags: 'central,tenant_users',
        );
        CentralOverviewService::forget();
    }

    private function findUser(string $userId): User
    {
        $user = User::query()->find($userId);
        if (! $user instanceof User) {
            abort(404, 'User not found in this tenant.', ['X-Error-Code' => 'TENANT_USER_NOT_FOUND']);
        }

        return $user;
    }
}
