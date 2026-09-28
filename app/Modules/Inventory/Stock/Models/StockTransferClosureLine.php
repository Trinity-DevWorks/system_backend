<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\Enums\StockTransferClosureOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'stock_transfer_closure_id',
    'stock_transfer_line_id',
    'item_id',
    'lot_id',
    'outcome',
    'quantity',
    'base_quantity',
    'item_uom_id',
    'stock_adjustment_reason_id',
    'notes',
])]
class StockTransferClosureLine extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => StockTransferClosureOutcome::class,
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<StockTransferClosure, $this>
     */
    public function stockTransferClosure(): BelongsTo
    {
        return $this->belongsTo(StockTransferClosure::class);
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

    /**
     * @return BelongsTo<StockAdjustmentReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(StockAdjustmentReason::class, 'stock_adjustment_reason_id');
    }
}
