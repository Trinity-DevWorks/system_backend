<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'bundle_explosion_id',
    'item_id',
    'bundle_item_id',
    'quantity',
    'base_quantity',
    'theoretical_quantity',
    'lot_id',
    'notes',
])]
class BundleExplosionLine extends Model implements AuditableContract
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
            'theoretical_quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<BundleExplosion, $this>
     */
    public function bundleExplosion(): BelongsTo
    {
        return $this->belongsTo(BundleExplosion::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<BundleItem, $this>
     */
    public function bundleItem(): BelongsTo
    {
        return $this->belongsTo(BundleItem::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
