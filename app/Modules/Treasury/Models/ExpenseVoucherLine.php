<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $expense_voucher_id
 * @property int $account_id
 * @property string|null $description
 * @property string $amount
 * @property int|null $vat_group_id
 * @property string $tax_rate
 * @property string $tax_amount
 */
#[Fillable(['expense_voucher_id', 'account_id', 'description', 'amount', 'vat_group_id', 'tax_rate', 'tax_amount', 'sort_order'])]
class ExpenseVoucherLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'tax_amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
