<?php

namespace App\Modules\Inventory\Item\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'item_id',
    'uom_id',
    'currency_id',
    'conversion_factor',
    'barcode',
    'selling_price',
    'cost_price',
    'takeaway_price',
    'dine_in_price',
    'delivery_price',
    'is_base',
    'is_default_sale',
    'is_default_purchase',
])]
class ItemUom extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:6',
            'selling_price' => 'decimal:4',
            'cost_price' => 'decimal:4',
            'takeaway_price' => 'decimal:4',
            'dine_in_price' => 'decimal:4',
            'delivery_price' => 'decimal:4',
            'is_base' => 'boolean',
            'is_default_sale' => 'boolean',
            'is_default_purchase' => 'boolean',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }
}
