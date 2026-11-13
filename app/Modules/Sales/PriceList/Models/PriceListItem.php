<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One price for an item unit in a list. min_quantity is a quantity break (0 = any quantity).
 *
 * @property int $id
 * @property int $price_list_id
 * @property string $item_id
 * @property int $item_uom_id
 * @property string $min_quantity
 * @property string $price
 */
#[Fillable(['price_list_id', 'item_id', 'item_uom_id', 'min_quantity', 'price'])]
class PriceListItem extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:6',
            'price' => 'decimal:6',
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
}
