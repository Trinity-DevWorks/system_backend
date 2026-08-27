<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Inventory\Stock\Enums\StockAdjustmentStatus;
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
 * @property StockAdjustmentStatus $status
 * @property Carbon|null $adjustment_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'adj_number',
    'warehouse_id',
    'stock_adjustment_reason_id',
    'status',
    'adjustment_date',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class StockAdjustment extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'stock_adjustment';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockAdjustmentStatus::class,
            'adjustment_date' => 'date',
            'posted_at' => 'datetime',
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
     * @return BelongsTo<StockAdjustmentReason, $this>
     */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(StockAdjustmentReason::class, 'stock_adjustment_reason_id');
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
     * @return HasMany<StockAdjustmentLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }
}
