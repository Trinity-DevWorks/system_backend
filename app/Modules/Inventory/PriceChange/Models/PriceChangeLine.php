<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\PriceChange\Enums\PriceField;
use App\Modules\Sales\PriceList\Models\PriceList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $price_change_id
 * @property string $item_id
 * @property int|null $item_uom_id
 * @property int|null $price_list_id
 * @property string|null $min_quantity
 * @property PriceField $field
 * @property string|null $old_value
 * @property string $new_value
 * @property int $sort_order
 */
#[Fillable([
    'price_change_id',
    'item_id',
    'item_uom_id',
    'price_list_id',
    'min_quantity',
    'field',
    'old_value',
    'new_value',
    'sort_order',
])]
class PriceChangeLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:6',
            'field' => PriceField::class,
            'old_value' => 'decimal:6',
            'new_value' => 'decimal:6',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceChange, $this>
     */
    public function priceChange(): BelongsTo
    {
        return $this->belongsTo(PriceChange::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemUom, $this>
     */
    public function itemUom(): BelongsTo
    {
        return $this->belongsTo(ItemUom::class);
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }
}
