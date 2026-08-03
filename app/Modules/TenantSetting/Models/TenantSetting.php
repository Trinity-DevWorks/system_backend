<?php

declare(strict_types=1);

namespace App\Modules\TenantSetting\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\TenantSetting\Enums\DateFormat;
use App\Modules\TenantSetting\Enums\NumberFormat;
use App\Modules\TenantSetting\Enums\PreferredLanguage;
use App\Modules\TenantSetting\Enums\PriceRoundingMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'primary_currency_id',
    'country',
    'preferred_language',
    'timezone',
    'date_format',
    'number_format',
    'tax_enabled',
    'allow_negative_stock',
    'price_rounding_mode',
    'price_decimal_places',
])]
class TenantSetting extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'tenant_settings';

    /**
     * Single row per tenant database.
     */
    public static function singleton(): self
    {
        /** @var self */
        return static::query()->firstOrCreate([], [
            'preferred_language' => PreferredLanguage::En,
            'timezone' => 'UTC',
            'date_format' => DateFormat::YmdDash,
            'number_format' => NumberFormat::CommaDot,
            'tax_enabled' => true,
            'allow_negative_stock' => false,
            'price_rounding_mode' => PriceRoundingMode::HalfUp,
            'price_decimal_places' => 2,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'preferred_language' => PreferredLanguage::class,
            'date_format' => DateFormat::class,
            'number_format' => NumberFormat::class,
            'tax_enabled' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'price_rounding_mode' => PriceRoundingMode::class,
            'price_decimal_places' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function primaryCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'primary_currency_id');
    }
}
