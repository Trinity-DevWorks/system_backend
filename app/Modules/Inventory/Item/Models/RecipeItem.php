<?php

namespace App\Modules\Inventory\Item\Models;

use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['recipe_id', 'item_id', 'quantity', 'uom_id'])]
class RecipeItem extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Recipe, $this>
     */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function ingredientItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }
}
