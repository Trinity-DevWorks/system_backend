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
     * Branches shown in the switcher (includes inactive — those are listed but not selectable).
     * Owner: all branches. Others: assigned branches.
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
            ->orderByDesc('is_active')
            ->orderByDesc('is_default')
            ->orderBy('name');

        if (! $this->isOwner($user)) {
            $query->whereIn('id', $this->assignedBranchIds($user, activeOnly: false));
        }

        return $query->get(['id', 'name', 'shortcut_name', 'is_default', 'is_active']);
    }

    /**
     * Branch IDs the user may actually work in (active only).
     *
     * @return list<int>
     */
    public function accessibleBranchIds(?User $user = null): array
    {
        return $this->accessibleBranches($user)
            ->filter(fn (Branch $b): bool => (bool) $b->is_active)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Whether the user may switch into / operate as this branch (must be active).
     */
    public function canAccessBranch(int $branchId, ?User $user = null): bool
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null) {
            return false;
        }

        if ($this->isOwner($user)) {
            return Branch::query()->whereKey($branchId)->where('is_active', true)->exists();
        }

        return in_array($branchId, $this->assignedBranchIds($user, activeOnly: true), true);
    }

    /**
     * Resolve the active branch for this request.
     * Prefers X-Branch-Id when valid; otherwise the user's saved preference; else default among accessible.
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

    /**
     * Persist the user's last chosen branch (no-op when unchanged or inaccessible).
     */
    public function rememberPreferredBranch(int $branchId, ?User $user = null): void
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null) {
            return;
        }

        if (! $this->canAccessBranch($branchId, $user)) {
            return;
        }

        if ((int) ($user->preferred_branch_id ?? 0) === $branchId) {
            return;
        }

        $user->forceFill(['preferred_branch_id' => $branchId])->save();
    }

    /**
     * Clear preferred branch when the user can no longer access it.
     */
    public function clearPreferredBranchIfInaccessible(?User $user = null): void
    {
        $user = $user ?? $this->authenticatedUser();
        if ($user === null || $user->preferred_branch_id === null) {
            return;
        }

        if (! $this->canAccessBranch((int) $user->preferred_branch_id, $user)) {
            $user->forceFill(['preferred_branch_id' => null])->save();
        }
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
     *   active_branch: array{id: int, name: string, shortcut_name: string|null, is_default: bool, is_active: bool}|null,
     *   preferred_branch_id: int|null,
     *   accessible_branches: list<array{id: int, name: string, shortcut_name: string|null, is_default: bool, is_active: bool}>,
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

        $preferredId = $user?->preferred_branch_id !== null ? (int) $user->preferred_branch_id : null;
        if ($preferredId !== null && ! $this->canAccessBranch($preferredId, $user)) {
            $preferredId = null;
        }

        return [
            'active_branch_id' => $activeId,
            'active_branch' => $active ? $this->branchToArray($active) : null,
            'preferred_branch_id' => $preferredId,
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
    private function assignedBranchIds(User $user, bool $activeOnly = true): array
    {
        $query = $user->branches();
        if ($activeOnly) {
            $query->where('branches.is_active', true);
        }

        return $query
            ->pluck('branches.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function fallbackBranchId(User $user): ?int
    {
        $switchable = $this->accessibleBranches($user)
            ->filter(fn (Branch $b): bool => (bool) $b->is_active)
            ->values();

        if ($switchable->isEmpty()) {
            return null;
        }

        $preferredId = $user->preferred_branch_id !== null ? (int) $user->preferred_branch_id : null;
        if ($preferredId !== null) {
            $preferred = $switchable->first(fn (Branch $b): bool => (int) $b->id === $preferredId);
            if ($preferred !== null) {
                return $preferredId;
            }
        }

        $default = $switchable->first(fn (Branch $b): bool => (bool) $b->is_default);

        return (int) ($default?->id ?? $switchable->first()->id);
    }

    /**
     * @return array{id: int, name: string, shortcut_name: string|null, is_default: bool, is_active: bool}
     */
    private function branchToArray(Branch $branch): array
    {
        return [
            'id' => (int) $branch->id,
            'name' => (string) $branch->name,
            'shortcut_name' => $branch->shortcut_name,
            'is_default' => (bool) $branch->is_default,
            'is_active' => (bool) $branch->is_active,
        ];
    }

    private function authenticatedUser(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
