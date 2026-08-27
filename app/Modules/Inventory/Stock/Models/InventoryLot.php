<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $expiry_date
 */
#[Fillable(['item_id', 'lot_number', 'expiry_date'])]
class InventoryLot extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return HasMany<StockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'lot_id');
    }

    public function isExpired(?string $onDate = null): bool
    {
        if ($this->expiry_date === null) {
            return false;
        }

        $day = $onDate ?? now()->toDateString();

        return $this->expiry_date->toDateString() < $day;
    }
}
