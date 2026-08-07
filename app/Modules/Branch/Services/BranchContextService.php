<?php

declare(strict_types=1);

namespace App\Modules\Branch\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BranchContextService
{
    public const HEADER = 'X-Branch-Id';

    public const OWNER_ROLE_NAME = 'Owner';

    /**
     * Branches the user may switch into.
     * Owner: all active branches. Others: assigned active branches.
     *
     * @return Collection<int, Branch>
     */
    public function accessibleBranches(?User $user = null): Collection
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null) {
            return new Collection;
        }

        $query = Branch::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $this->isOwner($user)) {
            $query->whereIn('id', $this->assignedBranchIds($user));
        }

        return $query->get(['id', 'name', 'shortcut_name', 'is_default', 'is_active']);
    }

    /**
     * @return list<int>
     */
    public function accessibleBranchIds(?User $user = null): array
    {
        return $this->accessibleBranches($user)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    public function canAccessBranch(int $branchId, ?User $user = null): bool
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null) {
            return false;
        }

        if ($this->isOwner($user)) {
            return Branch::query()->whereKey($branchId)->where('is_active', true)->exists();
        }

        return in_array($branchId, $this->assignedBranchIds($user), true);
    }

    /**
     * Resolve the active branch for this request.
     * Prefers X-Branch-Id when valid; otherwise default among accessible.
     */
    public function resolveActiveBranchId(?User $user = null): ?int
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null) {
            return null;
        }

        $header = request()->header(self::HEADER);
        if ($header !== null && $header !== '' && ctype_digit((string) $header)) {
            $requested = (int) $header;
            if ($this->canAccessBranch($requested, $user)) {
                return $requested;
            }
        }

        return $this->fallbackBranchId($user);
    }

    public function requireActiveBranchId(?User $user = null): int
    {
        $id = $this->resolveActiveBranchId($user);
        if ($id === null) {
            abort(422, 'No accessible branch available for this user.', [
                'X-Error-Code' => 'BRANCH_CONTEXT_REQUIRED',
            ]);
        }

        return $id;
    }

    /**
     * Owner if the user has the Owner role on any branch assignment.
     */
    public function isOwner(?User $user = null): bool
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null) {
            return false;
        }

        return DB::table('branch_user')
            ->join('roles', 'branch_user.role_id', '=', 'roles.id')
            ->where('branch_user.user_id', $user->id)
            ->where('roles.name', self::OWNER_ROLE_NAME)
            ->exists();
    }

    /**
     * @return array{
     *   active_branch_id: int|null,
     *   active_branch: array{id: int, name: string, shortcut_name: string|null, is_default: bool}|null,
     *   accessible_branches: list<array{id: int, name: string, shortcut_name: string|null, is_default: bool}>,
     *   is_owner: bool
     * }
     */
    public function contextPayload(?User $user = null): array
    {
        $user = $user ?? $this->authenticatedUser();
        $accessible = $this->accessibleBranches($user);
        $activeId = $this->resolveActiveBranchId($user);
        $active = $activeId !== null
            ? $accessible->first(fn (Branch $b): bool => (int) $b->id === $activeId)
            : null;

        if ($active === null && $activeId !== null) {
            $active = Branch::query()->whereKey($activeId)->first(['id', 'name', 'shortcut_name', 'is_default', 'is_active']);
        }

        return [
            'active_branch_id' => $activeId,
            'active_branch' => $active ? $this->branchToArray($active) : null,
            'accessible_branches' => $accessible
                ->map(fn (Branch $b): array => $this->branchToArray($b))
                ->values()
                ->all(),
            'is_owner' => $this->isOwner($user),
        ];
    }

    /**
     * @return list<int>
     */
    private function assignedBranchIds(User $user): array
    {
        return $user->branches()
            ->where('branches.is_active', true)
            ->pluck('branches.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function fallbackBranchId(User $user): ?int
    {
        $accessible = $this->accessibleBranches($user);
        if ($accessible->isEmpty()) {
            return null;
        }

        $default = $accessible->first(fn (Branch $b): bool => (bool) $b->is_default);

        return (int) ($default?->id ?? $accessible->first()->id);
    }

    /**
     * @return array{id: int, name: string, shortcut_name: string|null, is_default: bool}
     */
    private function branchToArray(Branch $branch): array
    {
        return [
            'id' => (int) $branch->id,
            'name' => (string) $branch->name,
            'shortcut_name' => $branch->shortcut_name,
            'is_default' => (bool) $branch->is_default,
        ];
    }

    private function authenticatedUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
