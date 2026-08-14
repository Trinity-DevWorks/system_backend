<?php

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
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
 * @property StockTransferStatus $status
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $received_at
 */
#[Fillable([
    'transfer_number',
    'from_warehouse_id',
    'to_warehouse_id',
    'status',
    'notes',
    'created_by',
    'dispatched_by',
    'dispatched_at',
    'received_by',
    'received_at',
])]
class StockTransfer extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'stock_transfer';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function fromWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function toWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
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
    public function dispatchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }
}
