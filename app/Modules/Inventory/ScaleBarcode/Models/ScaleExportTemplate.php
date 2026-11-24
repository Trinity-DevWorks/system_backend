<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Models;

use App\Modules\Branch\Models\Branch;
use App\Modules\Sales\PriceList\Models\PriceList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * How the item file for a scale's PC software is written: columns, separator, encoding, numbers.
 *
 * @property int $id
 * @property string $name
 * @property list<array<string, mixed>> $columns
 * @property string $delimiter
 * @property bool $has_header
 * @property string $encoding
 * @property string $line_ending
 * @property string $quote
 * @property string $file_extension
 * @property string $decimal_separator
 * @property int $price_decimals
 * @property string $price_multiplier
 * @property int $weight_decimals
 * @property string $price_source
 * @property int|null $price_list_id
 * @property int|null $branch_id
 * @property list<int>|null $category_ids
 * @property bool $is_active
 */
#[Fillable([
    'name', 'columns', 'delimiter', 'has_header', 'encoding', 'line_ending', 'quote', 'file_extension',
    'decimal_separator', 'price_decimals', 'price_multiplier', 'weight_decimals',
    'price_source', 'price_list_id', 'branch_id', 'category_ids', 'is_active',
])]
class ScaleExportTemplate extends Model implements AuditableContract
{
    use Auditable;

    public const DELIMITERS = [',', ';', 'tab', '|', 'fixed'];

    public const ENCODINGS = ['utf8', 'utf8_bom', 'windows1256'];

    public const EXTENSIONS = ['csv', 'txt', 'xlsx'];

    public const PRICE_SOURCES = ['item_price', 'price_list'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'category_ids' => 'array',
            'has_header' => 'boolean',
            'is_active' => 'boolean',
            'price_decimals' => 'integer',
            'weight_decimals' => 'integer',
            'price_multiplier' => 'decimal:6',
            'price_list_id' => 'integer',
            'branch_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<ScaleExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(ScaleExport::class);
    }
}
