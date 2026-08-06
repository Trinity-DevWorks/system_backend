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
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'transfer_number',
    'from_warehouse_id',
    'to_warehouse_id',
    'status',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
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
            'posted_at' => 'datetime',
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
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }
}
