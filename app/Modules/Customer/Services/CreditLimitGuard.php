<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerBalance;
use App\Services\PermissionService;
use App\Support\Decimal;
use Illuminate\Support\Facades\Auth;

/**
 * What: Blocks a sale that would push a customer's balance above their credit limit.
 * Where: SalesInvoiceService::post() and SalesOrderService::confirm(), inside their transactions.
 * Why: The limit was stored but never enforced. Limits are per currency (customer_balances);
 *      0 means "no limit". Users with credit_limit_override,edit may go over the limit.
 */
class CreditLimitGuard
{
    public const OVERRIDE_RESOURCE = 'credit_limit_override';

    public function __construct(
        private readonly CustomerLedgerService $ledger,
        private readonly PermissionService $permissions,
    ) {}

    /**
     * @param  string  $amount  New exposure in the document currency (e.g. invoice grand total).
     */
    public function assert(Customer $customer, int $currencyId, string $amount, ?User $user = null): void
    {
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

        abort(422, sprintf(
            'Credit limit exceeded: limit %s, current balance %s, this document %s.',
            Decimal::round($limit, 2),
            Decimal::round($balance, 2),
            Decimal::round($amount, 2),
        ), ['X-Error-Code' => 'CUSTOMER_CREDIT_LIMIT_EXCEEDED']);
    }
}
