<?php

declare(strict_types=1);

namespace App\Modules\Sales\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Services\ManagerApprovalService;
use App\Services\PermissionService;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Discount limits at posting time. Each role may have a highest discount %; above it someone allowed to
 * approve (the owner, a role with `discount_override`, or a role whose own limit covers it) gives their PIN
 * on the spot (see ManagerApprovalService).
 */
class DiscountGuard
{
    public const OVERRIDE_RESOURCE = ManagerApprovalService::DISCOUNT;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BranchContextService $branches,
        private readonly ManagerApprovalService $approvals,
    ) {}

    /**
     * @param  iterable<int, Model>  $lines  document lines (quantity, unit_price, discount_amount)
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval  approver and PIN typed at the counter
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

        $limit = $user instanceof User ? $this->limitFor($user, $branchId) : '0';
        $referenceType = defined($document::class.'::REFERENCE_TYPE') ? constant($document::class.'::REFERENCE_TYPE') : $document->getTable();

        return $this->approvals->approve(
            ManagerApprovalService::DISCOUNT,
            $approval,
            $branchId,
            'DISCOUNT_APPROVAL_REQUIRED',
            sprintf(
                'This discount (%s%%) is above your limit (%s%%). A manager must approve it.',
                Decimal::round($requested, 2),
                Decimal::round($limit ?? '0', 2),
            ),
            is_string($referenceType) ? $referenceType : null,
            $document->getKey() !== null ? (string) $document->getKey() : null,
            'Discount '.Decimal::round($requested, 2).'%',
            fn (User $approver): bool => $this->allows($approver, $requested, $branchId),
        );
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
