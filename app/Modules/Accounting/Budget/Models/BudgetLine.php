<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $budget_id
 * @property int $account_id
 * @property Carbon $period_month
 * @property string $amount
 */
#[Fillable([
    'budget_id',
    'account_id',
    'period_month',
    'amount',
])]
class BudgetLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'period_month' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
