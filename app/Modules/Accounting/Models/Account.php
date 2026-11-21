<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Currency\Models\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property AccountType|null $type
 * @property NormalBalance|null $normal_balance
 */
#[Fillable([
    'code',
    'name',
    'parent_id',
    'type',
    'normal_balance',
    'is_group',
    'is_active',
    'currency_id',
    'description',
])]
class Account extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'currency_id' => 'integer',
            'type' => AccountType::class,
            'normal_balance' => NormalBalance::class,
            'is_group' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function isPostable(): bool
    {
        return ! $this->is_group && $this->is_active;
    }
}
