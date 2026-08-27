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
    'stock_count_id',
    'item_id',
    'theoretical_quantity',
    'counted_quantity',
    'variance_quantity',
    'unit_cost',
    'lot_id',
    'notes',
])]
class StockCountLine extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theoretical_quantity' => 'decimal:6',
            'counted_quantity' => 'decimal:6',
            'variance_quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<StockCount, $this>
     */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
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
