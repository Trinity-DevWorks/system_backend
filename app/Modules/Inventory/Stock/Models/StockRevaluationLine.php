<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'stock_revaluation_id',
    'item_id',
    'lot_id',
    'quantity_on_hand',
    'base_quantity',
    'old_unit_cost',
    'old_inventory_value',
    'new_unit_cost',
    'new_inventory_value',
    'value_delta',
    'notes',
])]
class StockRevaluationLine extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'old_unit_cost' => 'decimal:4',
            'old_inventory_value' => 'decimal:4',
            'new_unit_cost' => 'decimal:4',
            'new_inventory_value' => 'decimal:4',
            'value_delta' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockRevaluation, $this>
     */
    public function stockRevaluation(): BelongsTo
    {
        return $this->belongsTo(StockRevaluation::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
