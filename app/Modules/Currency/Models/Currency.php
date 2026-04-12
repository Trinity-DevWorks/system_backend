<?php

declare(strict_types=1);

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'name',
    'code',
    'iso_code',
    'symbol',
    'smallest_unit',
    'round_limit',
    'acceptable_amount_overdue',
    'allowed_difference_in_receipt',
    'allowed_difference_in_payment',
    'active',
])]
class Currency extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'smallest_unit' => 'decimal:6',
            'round_limit' => 'decimal:6',
            'acceptable_amount_overdue' => 'decimal:4',
            'allowed_difference_in_receipt' => 'decimal:4',
            'allowed_difference_in_payment' => 'decimal:4',
        ];
    }

    public function isPrimary(): bool
    {
        $settings = TenantSetting::singleton();

        return $settings->primary_currency_id === $this->id;
    }

    public static function getPrimary(): ?self
    {
        $settings = TenantSetting::singleton();
        if (! $settings->primary_currency_id) {
            return null;
        }

        return static::query()->find($settings->primary_currency_id);
    }

    /**
     * @return HasMany<CurrencyPairRate, $this>
     */
    public function pairRatesFrom(): HasMany
    {
        return $this->hasMany(CurrencyPairRate::class, 'from_currency_id');
    }

    /**
     * @return HasMany<CurrencyPairRate, $this>
     */
    public function pairRatesTo(): HasMany
    {
        return $this->hasMany(CurrencyPairRate::class, 'to_currency_id');
    }
}
