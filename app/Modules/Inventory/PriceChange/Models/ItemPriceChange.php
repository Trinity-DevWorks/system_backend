<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Models;

use App\Models\User;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\PriceChange\Enums\PriceChangeSource;
use App\Modules\Sales\PriceList\Models\PriceList;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $item_id
 * @property int|null $item_uom_id
 * @property int|null $price_list_id
 * @property string $field
 * @property string|null $min_quantity
 * @property string|null $old_value
 * @property string|null $new_value
 * @property string|null $change_percent
 * @property PriceChangeSource $source
 * @property string|null $source_id
 * @property string|null $reason
 * @property Carbon $effective_at
 * @property string|null $changed_by
 * @property Carbon $created_at
 */
#[Fillable([
    'item_id',
    'item_uom_id',
    'price_list_id',
    'field',
    'min_quantity',
    'old_value',
    'new_value',
    'change_percent',
    'source',
    'source_id',
    'reason',
    'effective_at',
    'changed_by',
])]
class ItemPriceChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_quantity' => 'decimal:6',
            'old_value' => 'decimal:6',
            'new_value' => 'decimal:6',
            'change_percent' => 'decimal:4',
            'source' => PriceChangeSource::class,
            'effective_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @return BelongsTo<ItemUom, $this>
     */
    public function itemUom(): BelongsTo
    {
        return $this->belongsTo(ItemUom::class);
    }

    /**
     * @return BelongsTo<PriceList, $this>
     */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
