<?php

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'warehouse_id', 'lot_id', 'quantity', 'unit_cost', 'inventory_value'])]
class StockBalance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost' => 'decimal:4',
            'inventory_value' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }

    public static function onHandForWarehouse(string $itemId, int $warehouseId): string
    {
        $sum = static::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');

        return number_format((float) $sum, 6, '.', '');
    }

    public static function lockRow(string $itemId, int $warehouseId, ?int $lotId): self
    {
        $query = static::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->lockForUpdate();

        if ($lotId === null) {
            $query->whereNull('lot_id');
        } else {
            $query->where('lot_id', $lotId);
        }

        $balance = $query->first();
        if ($balance) {
            return $balance;
        }

        $created = static::query()->create([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'lot_id' => $lotId,
            'quantity' => 0,
        ]);

        return static::query()->whereKey($created->id)->lockForUpdate()->firstOrFail();
    }
}
