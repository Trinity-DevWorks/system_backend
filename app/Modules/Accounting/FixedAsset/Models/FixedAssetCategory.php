<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Models;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property DepreciationMethod $method
 * @property string $salvage_percent
 * @property string|null $declining_rate
 */
#[Fillable([
    'code', 'name',
    'asset_account_id', 'accumulated_account_id', 'expense_account_id',
    'disposal_gain_account_id', 'disposal_loss_account_id',
    'method', 'useful_life_months', 'declining_rate', 'salvage_percent',
    'prorate_first_month', 'is_active',
])]
class FixedAssetCategory extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => DepreciationMethod::class,
            'useful_life_months' => 'integer',
            'declining_rate' => 'decimal:4',
            'salvage_percent' => 'decimal:4',
            'prorate_first_month' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function disposalGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_gain_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function disposalLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_loss_account_id');
    }

    /**
     * @return HasMany<FixedAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(FixedAsset::class, 'category_id');
    }
}
