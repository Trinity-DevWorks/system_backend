<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Branch\Models\Branch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Treasury\Enums\CashBankAccountType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A cash box or bank account, backed by one GL asset account.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property CashBankAccountType $type
 * @property int $currency_id
 * @property int $account_id
 * @property int|null $branch_id
 * @property string $opening_balance
 * @property Carbon|null $opening_date
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'type', 'currency_id', 'account_id', 'branch_id', 'bank_name', 'iban', 'account_number', 'opening_balance', 'opening_date', 'is_active'])]
class CashBankAccount extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CashBankAccountType::class,
            'currency_id' => 'integer',
            'account_id' => 'integer',
            'branch_id' => 'integer',
            'opening_balance' => 'decimal:4',
            'opening_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
