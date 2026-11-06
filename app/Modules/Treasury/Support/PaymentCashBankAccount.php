<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Support;

use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentMethod\Models\PaymentMethodCashBankAccount;
use App\Modules\Treasury\Models\CashBankAccount;

/**
 * The cash box / bank account money moves through on a receipt, payment or refund: the one chosen on the
 * document, else the payment method's linked account for the document's currency and branch.
 */
final class PaymentCashBankAccount
{
    /**
     * Chosen account (checked) or the method's default; null only when `$required` is false and none fits.
     */
    public static function resolve(mixed $chosen, int $paymentMethodId, ?int $branchId, int $currencyId, bool $required): ?int
    {
        if ($chosen !== null && $chosen !== '') {
            return self::check((int) $chosen, $branchId, $currencyId);
        }

        $method = PaymentMethod::query()->find($paymentMethodId);
        $accountId = $method !== null ? PaymentMethodCashBankAccount::defaultFor($method, $branchId, $currencyId) : null;
        if ($accountId === null && $required) {
            abort(422, 'Choose the cash box or bank account for this payment, or link one to the payment method for this currency.', [
                'X-Error-Code' => 'PAYMENT_CASH_BANK_ACCOUNT_REQUIRED',
            ]);
        }

        return $accountId;
    }

    /**
     * Active, in the document currency, and shared or of the document's branch.
     */
    public static function check(int $accountId, ?int $branchId, int $currencyId): int
    {
        $account = CashBankAccount::query()->find($accountId);
        if ($account === null || ! $account->is_active) {
            abort(422, 'Choose an active cash/bank account for this payment.', ['X-Error-Code' => 'TREASURY_ACCOUNT_INACTIVE']);
        }
        if ((int) $account->currency_id !== $currencyId) {
            abort(422, 'The cash/bank account must be in the document currency.', ['X-Error-Code' => 'PAYMENT_CASH_BANK_CURRENCY_MISMATCH']);
        }
        if ($account->branch_id !== null && $branchId !== null && (int) $account->branch_id !== $branchId) {
            abort(422, 'This cash/bank account belongs to another branch.', ['X-Error-Code' => 'PAYMENT_CASH_BANK_OTHER_BRANCH']);
        }

        return (int) $account->id;
    }
}
