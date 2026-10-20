<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'stock_transfer_receipt_id',
    'stock_transfer_line_id',
    'item_id',
    'lot_id',
    'quantity',
    'base_quantity',
    'item_uom_id',
    'notes',
])]
class StockTransferReceiptLine extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<StockTransferReceipt, $this>
     */
    public function stockTransferReceipt(): BelongsTo
    {
        return $this->belongsTo(StockTransferReceipt::class);
    }

    /**
     * @return BelongsTo<StockTransferLine, $this>
     */
    public function stockTransferLine(): BelongsTo
    {
        return $this->belongsTo(StockTransferLine::class);
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
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
