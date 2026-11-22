<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One label layout printed by a scale: where the PLU and the weight / price / count sit.
 *
 * @property int $id
 * @property string $name
 * @property string $engine fixed | gs1
 * @property string $symbology ean13 | upca | code128 | gs1_128 | gs1_databar
 * @property list<string>|null $prefixes
 * @property int|null $total_length
 * @property int|null $plu_start
 * @property int|null $plu_length
 * @property int|null $value_start
 * @property int|null $value_length
 * @property string $value_type weight | price | count
 * @property int $decimals
 * @property string $multiplier
 * @property int|null $uom_id
 * @property int|null $currency_id
 * @property bool $price_includes_tax
 * @property bool $verify_check_digit
 * @property int|null $price_check_digit_position
 * @property int $priority
 * @property bool $is_active
 */
#[Fillable([
    'name', 'engine', 'symbology', 'prefixes', 'total_length',
    'plu_start', 'plu_length', 'value_start', 'value_length',
    'value_type', 'decimals', 'multiplier', 'uom_id', 'currency_id',
    'price_includes_tax', 'verify_check_digit', 'price_check_digit_position',
    'priority', 'is_active',
])]
class ScaleBarcodeFormat extends Model implements AuditableContract
{
    use Auditable;

    public const ENGINE_FIXED = 'fixed';

    public const ENGINE_GS1 = 'gs1';

    public const ENGINES = [self::ENGINE_FIXED, self::ENGINE_GS1];

    public const SYMBOLOGIES = ['ean13', 'upca', 'code128', 'gs1_128', 'gs1_databar'];

    public const TYPE_WEIGHT = 'weight';

    public const TYPE_PRICE = 'price';

    public const TYPE_COUNT = 'count';

    public const VALUE_TYPES = [self::TYPE_WEIGHT, self::TYPE_PRICE, self::TYPE_COUNT];

    /** Symbologies with a mod-10 check digit as their last character. */
    public const MOD10_SYMBOLOGIES = ['ean13', 'upca'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prefixes' => 'array',
            'total_length' => 'integer',
            'plu_start' => 'integer',
            'plu_length' => 'integer',
            'value_start' => 'integer',
            'value_length' => 'integer',
            'decimals' => 'integer',
            'multiplier' => 'decimal:6',
            'uom_id' => 'integer',
            'currency_id' => 'integer',
            'price_includes_tax' => 'boolean',
            'verify_check_digit' => 'boolean',
            'price_check_digit_position' => 'integer',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Empty = every branch.
     *
     * @return BelongsToMany<Branch, $this>
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'scale_barcode_format_branch');
    }
}
