<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $delivery_note_id
 * @property int|null $sales_order_line_id
 * @property string $item_id
 * @property int|null $item_uom_id
 * @property int|null $warehouse_id
 * @property int|null $lot_id
 * @property string $quantity
 * @property string $base_quantity
 * @property string $conversion_factor
 * @property string $invoiced_base_quantity
 * @property string|null $description
 * @property string|null $notes
 */
#[Fillable([
    'delivery_note_id', 'sales_order_line_id', 'item_id', 'item_uom_id', 'warehouse_id', 'lot_id', 'sort_order',
    'quantity', 'base_quantity', 'conversion_factor', 'invoiced_base_quantity', 'description', 'notes',
])]
class DeliveryNoteLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:6',
            'invoiced_base_quantity' => 'decimal:6',
        ];
    }

    /** Delivered quantity (base unit) not invoiced yet. */
    public function uninvoicedBaseQuantity(): string
    {
        $remaining = bcsub((string) $this->base_quantity, (string) $this->invoiced_base_quantity, 6);

        return bccomp($remaining, '0', 6) < 0 ? '0.000000' : $remaining;
    }

    public function uninvoicedQuantity(): string
    {
        $factor = bccomp((string) $this->conversion_factor, '0', 6) > 0 ? (string) $this->conversion_factor : '1';

        return bcdiv($this->uninvoicedBaseQuantity(), $factor, 6);
    }

    /**
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /**
     * @return BelongsTo<SalesOrderLine, $this>
     */
    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
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
