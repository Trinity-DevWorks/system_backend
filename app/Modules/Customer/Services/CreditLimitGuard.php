<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerBalance;
use App\Modules\Rbac\Services\ManagerApprovalService;
use App\Services\PermissionService;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;

/**
 * What: Blocks a sale that would push a customer's balance above their credit limit.
 * Where: SalesInvoiceService::post() and SalesOrderService::confirm(), inside their transactions.
 * Why: The limit was stored but never enforced. Limits are per currency (customer_balances);
 *      0 means "no limit". Users with credit_limit_override,edit may go over the limit; anyone else needs
 *      the OK (PIN) of someone who has it (see ManagerApprovalService).
 */
class CreditLimitGuard
{
    public const OVERRIDE_RESOURCE = ManagerApprovalService::CREDIT_LIMIT;

    public function __construct(
        private readonly CustomerLedgerService $ledger,
        private readonly PermissionService $permissions,
        private readonly ManagerApprovalService $approvals,
    ) {}

    /**
     * @param  string  $amount  New exposure in the document currency (e.g. invoice grand total).
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval  a manager's PIN when the user may not go over
     */
    public function assert(
        Customer $customer,
        int $currencyId,
        string $amount,
        ?User $user = null,
        ?array $approval = null,
        ?int $branchId = null,
        ?string $documentType = null,
        ?string $documentId = null,
    ): void {
        if ($customer->is_system || ! Decimal::isPositive($amount)) {
            return;
        }

        // Lock the limit row so two concurrent posts for the same customer are checked one after the other.
        $limitRow = CustomerBalance::query()
            ->where('customer_id', $customer->id)
            ->where('currency_id', $currencyId)
            ->lockForUpdate()
            ->first();

        $limit = Decimal::of($limitRow?->credit_limit);
        if (! Decimal::isPositive($limit)) {
            return;
        }

        $balance = $this->ledger->balanceInCurrency($customer, $currencyId);
        $exposure = Decimal::add($balance, $amount);
        if (Decimal::cmp($exposure, $limit) <= 0) {
            return;
        }

        $user ??= Auth::user() instanceof User ? Auth::user() : null;
        if ($user instanceof User && $this->permissions->userHas(self::OVERRIDE_RESOURCE, 'edit', $user)) {
            return;
        }

        $this->approvals->approve(
            ManagerApprovalService::CREDIT_LIMIT,
            $approval,
            $branchId,
            'CUSTOMER_CREDIT_LIMIT_EXCEEDED',
            sprintf(
                'Credit limit exceeded: limit %s, current balance %s, this document %s.',
                Decimal::round($limit, 2),
                Decimal::round($balance, 2),
                Decimal::round($amount, 2),
            ),
            $documentType,
            $documentId,
            'Over credit limit by '.Decimal::round(Decimal::sub($exposure, $limit), 2).' ('.$customer->name.')',
        );
    }
}
