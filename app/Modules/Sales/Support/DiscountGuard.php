<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Rbac\Models\Role;
use App\Services\PermissionService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Discount limits at posting time. Each role may have a highest discount %; above it a user with
 * `discount_override` (a manager) must approve by entering their login on the spot.
 */
class DiscountGuard
{
    public const OVERRIDE_RESOURCE = 'discount_override';

    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BranchContextService $branches,
    ) {}

    /**
     * @param  iterable<int, Model>  $lines  document lines (quantity, unit_price, discount_amount)
     * @param  array{email?: mixed, password?: mixed}|null  $approval  manager login typed at the counter
     * @return string|null the approving manager's user id, or null when no approval was needed
     */
    public function assert(Model $document, iterable $lines, ?array $approval, ?User $user = null): ?string
    {
        $requested = $this->discountPercent($document, $lines);
        if (! Decimal::isPositive($requested)) {
            return null;
        }

        $user ??= Auth::user() instanceof User ? Auth::user() : null;
        $branchId = (int) $document->getAttribute('branch_id');
        if ($user instanceof User && $this->allows($user, $requested, $branchId)) {
            return null;
        }

        $email = is_array($approval) ? trim((string) ($approval['email'] ?? '')) : '';
        $password = is_array($approval) ? (string) ($approval['password'] ?? '') : '';
        if ($email === '' || $password === '') {
            $limit = $user instanceof User ? $this->limitFor($user, $branchId) : '0';
            abort(422, sprintf(
                'This discount (%s%%) is above your limit (%s%%). A manager must approve it.',
                Decimal::round($requested, 2),
                Decimal::round($limit ?? '0', 2),
            ), ['X-Error-Code' => 'DISCOUNT_APPROVAL_REQUIRED']);
        }

        $key = 'discount-approval:'.($user?->getKey() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            abort(429, 'Too many approval attempts. Try again in a minute.', ['X-Error-Code' => 'DISCOUNT_APPROVAL_THROTTLED']);
        }

        $approver = User::query()->where('email', $email)->first();
        if (! $approver instanceof User
            || ! $approver->is_active
            || ! Hash::check($password, (string) $approver->password)
            || ! $this->branches->canAccessBranch($branchId, $approver)
            || ! $this->allows($approver, $requested, $branchId)) {
            RateLimiter::hit($key, 60);
            abort(422, 'This login cannot approve the discount.', ['X-Error-Code' => 'DISCOUNT_APPROVAL_INVALID']);
        }
        RateLimiter::clear($key);

        return (string) $approver->getKey();
    }

    /**
     * Largest discount on the document, in %: the whole document, or any single line.
     *
     * @param  iterable<int, Model>  $lines
     */
    public function discountPercent(Model $document, iterable $lines): string
    {
        $percent = $this->share((string) $document->getAttribute('discount_total'), (string) $document->getAttribute('subtotal'));
        foreach ($lines as $line) {
            $merchandise = Decimal::mul((string) $line->getAttribute('quantity'), (string) $line->getAttribute('unit_price'));
            $percent = Decimal::max($percent, $this->share((string) $line->getAttribute('discount_amount'), $merchandise));
        }

        return Decimal::round($percent, 4);
    }

    private function allows(User $user, string $percent, int $branchId): bool
    {
        if ($this->branches->isOwner($user) || $this->permissions->userHas(self::OVERRIDE_RESOURCE, 'edit', $user)) {
            return true;
        }
        $limit = $this->limitFor($user, $branchId);

        return $limit === null || Decimal::cmp($percent, $limit) <= 0;
    }

    /**
     * The user's role limit in this branch, or null for no limit.
     */
    private function limitFor(User $user, int $branchId): ?string
    {
        $roleId = $this->permissions->resolveEffectiveRoleId($user, $branchId > 0 ? $branchId : null);
        $limit = $roleId !== null ? Role::query()->whereKey($roleId)->value('max_discount_percent') : null;

        return $limit !== null ? (string) $limit : null;
    }

    private function share(string $part, string $whole): string
    {
        return Decimal::isPositive($whole) ? Decimal::mul(Decimal::div($part, $whole, 12), '100') : '0';
    }
}
