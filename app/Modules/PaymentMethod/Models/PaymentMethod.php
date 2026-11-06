<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property PaymentMethodType $type
 * @property bool $supports_change
 * @property bool $requires_reference
 * @property bool $is_active
 */
#[Fillable([
    'code',
    'name',
    'type',
    'currency_id',
    'requires_reference',
    'supports_change',
    'is_active',
    'notes',
])]
class PaymentMethod extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PaymentMethodType::class,
            'requires_reference' => 'boolean',
            'supports_change' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<PaymentMethodCashBankAccount, $this>
     */
    public function cashBankAccounts(): HasMany
    {
        return $this->hasMany(PaymentMethodCashBankAccount::class);
    }
}
