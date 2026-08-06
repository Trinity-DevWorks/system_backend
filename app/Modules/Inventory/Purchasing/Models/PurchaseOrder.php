<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Models;

use App\Models\User;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Supplier\Models\Supplier;
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
 * @property PurchaseOrderStatus $status
 * @property Carbon|null $order_date
 * @property Carbon|null $expected_date
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $sent_at
 */
#[Fillable([
    'po_number',
    'supplier_id',
    'warehouse_id',
    'status',
    'order_date',
    'expected_date',
    'notes',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'sent_by',
    'sent_at',
])]
class PurchaseOrder extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'purchase_order';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'order_date' => 'date',
            'expected_date' => 'date',
            'confirmed_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
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
    public function confirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }
}
