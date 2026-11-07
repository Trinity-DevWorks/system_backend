<?php

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\HasDocumentBranch;
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
 * @property int $from_branch_id
 * @property int $to_branch_id
 * @property StockTransferStatus $status
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $received_at
 */
#[Fillable([
    'from_branch_id',
    'to_branch_id',
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
    use HasDocumentBranch;
    use HasUuids;

    /**
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return [
            'from_branch_id' => 'from_warehouse_id',
            'to_branch_id' => 'to_warehouse_id',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

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

    /**
     * @return HasMany<StockTransferReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(StockTransferReceipt::class);
    }

    /**
     * @return HasMany<StockTransferClosure, $this>
     */
    public function closures(): HasMany
    {
        return $this->hasMany(StockTransferClosure::class);
    }
}
