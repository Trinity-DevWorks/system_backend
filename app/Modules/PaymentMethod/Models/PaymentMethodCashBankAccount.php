<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Models;

use App\Modules\Treasury\Models\CashBankAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash box / bank account a payment method uses (Cash -> Cash USD, Cash LBP, ...).
 *
 * @property int $payment_method_id
 * @property int $cash_bank_account_id
 */
#[Fillable(['payment_method_id', 'cash_bank_account_id'])]
class PaymentMethodCashBankAccount extends Model
{
    /**
     * The account a payment goes to: among the method's linked active accounts in this currency, the one of
     * this branch, else the shared one (no branch). Null when the method has none in that currency.
     */
    public static function defaultFor(PaymentMethod $method, ?int $branchId, int $currencyId): ?int
    {
        $accounts = CashBankAccount::query()
            ->whereIn('id', self::query()->where('payment_method_id', $method->id)->select('cash_bank_account_id'))
            ->where('is_active', true)
            ->where('currency_id', $currencyId)
            ->where(fn ($q) => $q->whereNull('branch_id')->when($branchId !== null, fn ($b) => $b->orWhere('branch_id', $branchId)))
            ->get(['id', 'branch_id']);
        $own = $branchId !== null ? $accounts->firstWhere('branch_id', $branchId) : null;
        $account = $own ?? $accounts->firstWhere('branch_id', null);

        return $account !== null ? (int) $account->id : null;
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }
}
