<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Services;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;
use App\Services\PermissionService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One way to get a manager's OK on the spot: the approver picks their name and types their PIN.
 *
 * Who can approve an action is decided by roles: the owner always, and anyone whose role has the action's
 * permission (e.g. `discount_override`, `credit_limit_override`) with "edit". Every approval is written to
 * manager_approvals (who approved what, for whom, on which document). Wrong PINs are throttled.
 */
class ManagerApprovalService
{
    /** Discount above the poster's role limit. */
    public const DISCOUNT = 'discount_override';

    /** Sale above the customer's credit limit. */
    public const CREDIT_LIMIT = 'credit_limit_override';

    private const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 300;

    public function __construct(
        private readonly PermissionService $permissions,
        private readonly BranchContextService $branches,
    ) {}

    /**
     * Checks the approval sent with the request and records it. With no approval, or one that cannot approve,
     * the request stops with `$requiredCode` / MANAGER_APPROVAL_INVALID so the screen can ask for a PIN.
     *
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval
     * @param  (callable(User): bool)|null  $alsoAllowed  extra rule for approvers without the permission (e.g. a role limit that covers it)
     * @return string the approver's user id
     */
    public function approve(
        string $action,
        ?array $approval,
        ?int $branchId,
        string $requiredCode,
        string $requiredMessage,
        ?string $documentType = null,
        ?string $documentId = null,
        ?string $detail = null,
        ?callable $alsoAllowed = null,
    ): string {
        $approverId = is_array($approval) ? trim((string) ($approval['approver_id'] ?? '')) : '';
        $pin = is_array($approval) ? trim((string) ($approval['pin'] ?? '')) : '';
        if ($approverId === '' || $pin === '') {
            abort(422, $requiredMessage, ['X-Error-Code' => $requiredCode]);
        }

        $requester = Auth::user() instanceof User ? Auth::user() : null;
        $key = 'manager-approval:'.(tenant('id') ?? 'central').':'.($requester?->getKey() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            abort(429, 'Too many wrong PINs. Try again in a few minutes.', ['X-Error-Code' => 'MANAGER_APPROVAL_THROTTLED']);
        }

        $approver = User::query()->whereKey($approverId)->first();
        if (! $approver instanceof User
            || ! $approver->is_active
            || $approver->approval_pin === null
            || ! Hash::check($pin, (string) $approver->approval_pin)
            || ($branchId !== null && $branchId > 0 && ! $this->branches->canAccessBranch($branchId, $approver))
            || ! ($this->canApprove($approver, $action) || ($alsoAllowed !== null && $alsoAllowed($approver)))) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            abort(422, 'This PIN cannot approve this.', ['X-Error-Code' => 'MANAGER_APPROVAL_INVALID']);
        }
        RateLimiter::clear($key);

        DB::table('manager_approvals')->insert([
            'action' => $action,
            'approver_id' => $approver->getKey(),
            'requested_by' => $requester?->getKey(),
            'branch_id' => $branchId !== null && $branchId > 0 ? $branchId : null,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
            'created_at' => now(),
        ]);

        return (string) $approver->getKey();
    }

    public function canApprove(User $user, string $action): bool
    {
        return $this->branches->isOwner($user) || $this->permissions->userHas($action, 'edit', $user);
    }

    /**
     * People who can approve `$action` in the branch and have a PIN: the names shown in the approval window.
     *
     * @return list<array{id: string, name: string}>
     */
    public function approvers(string $action, ?int $branchId): array
    {
        $out = [];
        $users = User::query()->where('is_active', true)->whereNotNull('approval_pin')->orderBy('name')->get();
        foreach ($users as $user) {
            if ($branchId !== null && $branchId > 0 && ! $this->branches->canAccessBranch($branchId, $user)) {
                continue;
            }
            if ($this->canApprove($user, $action)) {
                $out[] = ['id' => (string) $user->getKey(), 'name' => (string) $user->name];
            }
        }

        return $out;
    }

    /**
     * Sets (or with null removes) the user's own approval PIN, after checking their password.
     */
    public function setPin(User $user, ?string $pin, string $currentPassword): void
    {
        if ($currentPassword === '' || ! Hash::check($currentPassword, (string) $user->getAuthPassword())) {
            throw new HttpResponseException(ApiResponse::error(
                'The current password is incorrect.',
                422,
                null,
                ['current_password' => ['The current password is incorrect.']],
                null,
                null,
                'CURRENT_PASSWORD_INVALID',
            ));
        }
        $user->forceFill(['approval_pin' => $pin !== null && $pin !== '' ? Hash::make($pin) : null])->save();
    }
}
