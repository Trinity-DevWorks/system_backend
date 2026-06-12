<?php

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'item_id',
    'warehouse_id',
    'safety_stock_qty',
    'reorder_point_qty',
    'reorder_qty',
    'max_qty',
    'lead_time_days',
    'is_active',
])]
class ItemWarehouseReplenishment extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'safety_stock_qty' => 'decimal:6',
            'reorder_point_qty' => 'decimal:6',
            'reorder_qty' => 'decimal:6',
            'max_qty' => 'decimal:6',
            'lead_time_days' => 'integer',
            'is_active' => 'boolean',
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
}
