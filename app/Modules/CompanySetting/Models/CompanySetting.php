<?php

declare(strict_types=1);

namespace App\Modules\CompanySetting\Models;

use App\Modules\CompanySetting\Enums\DateFormat;
use App\Modules\CompanySetting\Enums\NumberFormat;
use App\Modules\CompanySetting\Enums\PreferredLanguage;
use App\Modules\CompanySetting\Enums\PriceRoundingMode;
use App\Modules\CompanySetting\Enums\TaxPriceMode;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Stock\Enums\InventoryCostingMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property PreferredLanguage $preferred_language
 * @property DateFormat $date_format
 * @property NumberFormat $number_format
 * @property PriceRoundingMode $price_rounding_mode
 * @property TaxPriceMode $tax_price_mode
 * @property InventoryCostingMethod $inventory_costing_method
 */
#[Fillable([
    'primary_currency_id',
    'country',
    'preferred_language',
    'timezone',
    'date_format',
    'number_format',
    'tax_enabled',
    'tax_price_mode',
    'allow_negative_stock',
    'inventory_costing_method',
    'price_rounding_mode',
    'price_decimal_places',
])]
class CompanySetting extends Model implements AuditableContract
{
    use Auditable;

    protected $table = 'company_settings';

    /**
     * Single row per tenant database (DB read — prefer current() for hot paths).
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
            'tax_price_mode' => TaxPriceMode::Exclusive,
            'allow_negative_stock' => false,
            'inventory_costing_method' => InventoryCostingMethod::MovingAverage,
            'price_rounding_mode' => PriceRoundingMode::HalfUp,
            'price_decimal_places' => 2,
        ]);
    }

    /**
     * Cached singleton for domain reads (Redis when CACHE_STORE=redis).
     */
    public static function current(): self
    {
        return app(CompanySettingService::class)->get();
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
            'tax_price_mode' => TaxPriceMode::class,
            'allow_negative_stock' => 'boolean',
            'inventory_costing_method' => InventoryCostingMethod::class,
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

    /**
     * Catalog prices already include VAT. False when tax is off, regardless of mode.
     */
    public function pricesIncludeTax(): bool
    {
        return $this->tax_price_mode->pricesIncludeTax((bool) $this->tax_enabled);
    }

    public function allowsNegativeStock(): bool
    {
        return (bool) $this->allow_negative_stock;
    }
}
