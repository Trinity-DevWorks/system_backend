<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Branch\Support\HasDocumentBranch;
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
 * @property int $branch_id
 * @property Carbon|null $closed_at
 */
#[Fillable([
    'branch_id',
    'closure_number',
    'stock_transfer_id',
    'warehouse_id',
    'notes',
    'created_by',
    'closed_at',
])]
class StockTransferClosure extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    /**
     * A closure writes off on the sending side of its transfer.
     */
    public function documentBranchFromParent(): ?int
    {
        return $this->branchOfDocument(StockTransfer::class, $this->stock_transfer_id, 'from_branch_id');
    }

    /**
     * Closures are recorded in one step, so their branch never changes after creation.
     */
    public function documentBranchIsFrozen(): bool
    {
        return true;
    }

    public const REFERENCE_TYPE = 'stock_transfer_closure';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class);
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
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<StockTransferClosureLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferClosureLine::class);
    }
}
