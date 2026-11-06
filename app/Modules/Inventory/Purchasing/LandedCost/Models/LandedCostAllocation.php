<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One charge's share for one receipt line. At posting the share is split into what went to stock
 * (inventory_amount), to cost of sales for units already sold (cogs_amount) and, for standard-costed
 * items, to the purchase price difference (variance_amount).
 *
 * @property int $id
 * @property int $landed_cost_charge_id
 * @property string $receipt_source_type goods_receipt | purchase_invoice
 * @property int $receipt_line_id
 * @property int|null $source_movement_id
 * @property string $item_id
 * @property int $warehouse_id
 * @property int|null $lot_id
 * @property string $received_quantity
 * @property string $basis
 * @property string $amount
 * @property string|null $manual_amount
 * @property string $on_hand_basis
 * @property string $inventory_amount
 * @property string $cogs_amount
 * @property string $variance_amount
 */
#[Fillable([
    'landed_cost_charge_id', 'receipt_source_type', 'receipt_line_id', 'source_movement_id', 'item_id', 'warehouse_id', 'lot_id',
    'received_quantity', 'basis', 'amount', 'manual_amount', 'on_hand_basis', 'inventory_amount', 'cogs_amount', 'variance_amount',
])]
class LandedCostAllocation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'receipt_line_id' => 'integer',
            'source_movement_id' => 'integer',
            'warehouse_id' => 'integer',
            'lot_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LandedCostCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(LandedCostCharge::class, 'landed_cost_charge_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class);
    }
}
