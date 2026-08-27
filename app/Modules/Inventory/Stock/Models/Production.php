<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Models\Recipe;
use App\Modules\Inventory\Stock\Enums\ProductionStatus;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property ProductionStatus $status
 * @property Carbon|null $production_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'prd_number',
    'warehouse_id',
    'item_id',
    'recipe_id',
    'status',
    'production_date',
    'quantity',
    'base_quantity',
    'yield_quantity',
    'item_uom_id',
    'lot_id',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class Production extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'production';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductionStatus::class,
            'production_date' => 'date',
            'posted_at' => 'datetime',
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'yield_quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
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
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return HasMany<ProductionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLine::class);
    }
}
