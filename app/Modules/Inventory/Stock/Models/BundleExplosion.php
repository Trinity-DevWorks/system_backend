<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Models\User;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\BundleExplosionStatus;
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
 * @property BundleExplosionStatus $status
 * @property Carbon|null $explosion_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'bex_number',
    'warehouse_id',
    'item_id',
    'status',
    'explosion_date',
    'quantity',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class BundleExplosion extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'bundle_explosion';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BundleExplosionStatus::class,
            'explosion_date' => 'date',
            'posted_at' => 'datetime',
            'quantity' => 'decimal:6',
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
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
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
     * @return HasMany<BundleExplosionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BundleExplosionLine::class);
    }
}
