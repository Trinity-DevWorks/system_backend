<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Inventory\Stock\Enums\StockCountStatus;
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
 * @property StockCountStatus $status
 * @property Carbon|null $count_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'cnt_number',
    'warehouse_id',
    'status',
    'count_date',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class StockCount extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'stock_count';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockCountStatus::class,
            'count_date' => 'date',
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
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
