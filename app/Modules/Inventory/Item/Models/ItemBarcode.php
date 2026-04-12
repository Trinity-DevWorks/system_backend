<?php

namespace App\Modules\Inventory\Item\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemBarcode extends Model
{
    #[Fillable(['item_id', 'item_unit_of_measurement_id', 'barcode', 'is_primary'])]

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
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
     * @return BelongsTo<ItemUnitOfMeasurement, $this>
     */
    public function itemUnitOfMeasurement(): BelongsTo
    {
        return $this->belongsTo(ItemUnitOfMeasurement::class);
    }
}
