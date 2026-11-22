<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * History row: one serial moved by one stock movement.
 *
 * @property int $id
 * @property int $inventory_serial_id
 * @property int|null $stock_movement_id
 * @property string|null $reference_type
 * @property string|null $reference_id
 * @property string $movement_type
 * @property string $direction
 * @property bool $is_reversal
 * @property Carbon|null $reversed_at
 * @property int|null $warehouse_id
 * @property string|null $status_before
 * @property int|null $warehouse_before_id
 * @property string $status_after
 * @property string|null $party_type
 * @property string|null $party_id
 * @property string|null $user_id
 * @property Carbon $moved_at
 */
#[Fillable([
    'inventory_serial_id', 'stock_movement_id', 'reference_type', 'reference_id',
    'movement_type', 'direction', 'is_reversal', 'reversed_at', 'warehouse_id',
    'status_before', 'warehouse_before_id', 'status_after',
    'party_type', 'party_id', 'user_id', 'moved_at',
])]
class InventorySerialMovement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_reversal' => 'boolean',
            'reversed_at' => 'datetime',
            'moved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InventorySerial, $this>
     */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'inventory_serial_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
