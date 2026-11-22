<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Deferral\Enums\DeferralSourceType;
use App\Modules\Deferral\Models\DeferralSchedule;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $expense_voucher_id
 * @property int $account_id
 * @property string|null $description
 * @property string $amount
 * @property int|null $vat_group_id
 * @property string $tax_rate
 * @property string $tax_amount
 * @property Carbon|null $deferral_start_date
 * @property Carbon|null $deferral_end_date
 * @property int|null $deferral_recognition_account_id
 * @property string|null $deferral_method
 */
#[Fillable([
    'expense_voucher_id', 'account_id', 'description', 'amount', 'vat_group_id', 'tax_rate', 'tax_amount', 'sort_order',
    'deferral_start_date', 'deferral_end_date', 'deferral_recognition_account_id', 'deferral_method',
])]
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
            'deferral_start_date' => 'date',
            'deferral_end_date' => 'date',
            'deferral_recognition_account_id' => 'integer',
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
     * @return BelongsTo<Account, $this>
     */
    public function deferralRecognitionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deferral_recognition_account_id');
    }

    /**
     * @return HasOne<DeferralSchedule, $this>
     */
    public function deferralSchedule(): HasOne
    {
        return $this->hasOne(DeferralSchedule::class, 'source_line_id')
            ->where('source_type', DeferralSourceType::ExpenseVoucher->value);
    }
}
