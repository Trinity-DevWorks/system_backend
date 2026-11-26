<?php

namespace App\Modules\Inventory\Item\Models;

use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'item_id',
    'uom_id',
    'conversion_factor',
    'selling_price',
    'cost_price',
    'is_base',
    'is_default_sale',
    'is_default_purchase',
])]
class ItemUom extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:12',
            'selling_price' => 'decimal:6',
            'cost_price' => 'decimal:6',
            'is_base' => 'boolean',
            'is_default_sale' => 'boolean',
            'is_default_purchase' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    /**
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }

    /**
     * The barcode shown for this unit: its primary one, else its oldest. Barcodes live only in item_barcodes.
     *
     * @return HasOne<ItemBarcode, $this>
     */
    public function primaryBarcode(): HasOne
    {
        return $this->hasOne(ItemBarcode::class)->orderByDesc('is_primary')->orderBy('id');
    }

    /**
     * `$itemUom->barcode`: the unit's barcode from item_barcodes (uses a loaded `primaryBarcode` or `barcodes`).
     *
     * @return Attribute<?string, never>
     */
    protected function barcode(): Attribute
    {
        return Attribute::make(get: function (): ?string {
            if ($this->relationLoaded('primaryBarcode')) {
                $row = $this->getRelation('primaryBarcode');
            } elseif ($this->relationLoaded('barcodes')) {
                $row = $this->barcodes->sortBy([['is_primary', 'desc'], ['id', 'asc']])->first();
            } else {
                $row = $this->primaryBarcode;
            }

            return $row instanceof ItemBarcode ? (string) $row->barcode : null;
        });
    }
}
