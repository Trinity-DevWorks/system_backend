<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One physical unit of a serial-tracked item.
 *
 * @property int $id
 * @property string $item_id
 * @property string $serial_number
 * @property string $status
 * @property int|null $warehouse_id
 * @property string|null $supplier_id
 * @property Carbon|null $received_at
 * @property string|null $received_ref_type
 * @property string|null $received_ref_id
 * @property string|null $customer_id
 * @property Carbon|null $sold_at
 * @property string|null $sold_ref_type
 * @property string|null $sold_ref_id
 * @property Carbon|null $warranty_ends_on
 * @property string|null $notes
 */
#[Fillable([
    'item_id', 'serial_number', 'status', 'warehouse_id',
    'supplier_id', 'received_at', 'received_ref_type', 'received_ref_id',
    'customer_id', 'sold_at', 'sold_ref_type', 'sold_ref_id',
    'warranty_ends_on', 'notes',
])]
class InventorySerial extends Model implements AuditableContract
{
    use Auditable;

    public const STATUS_IN_STOCK = 'in_stock';

    public const STATUS_IN_TRANSIT = 'in_transit';

    public const STATUS_SOLD = 'sold';

    public const STATUS_RETURNED_TO_SUPPLIER = 'returned_to_supplier';

    public const STATUS_CONSUMED = 'consumed';

    public const STATUS_SCRAPPED = 'scrapped';

    /** Its only receipt was reversed: the unit was never really here. */
    public const STATUS_VOID = 'void';

    public const STATUSES = [
        self::STATUS_IN_STOCK,
        self::STATUS_IN_TRANSIT,
        self::STATUS_SOLD,
        self::STATUS_RETURNED_TO_SUPPLIER,
        self::STATUS_CONSUMED,
        self::STATUS_SCRAPPED,
        self::STATUS_VOID,
    ];

    /** @var list<string> */
    protected $auditInclude = ['status', 'warehouse_id', 'customer_id', 'warranty_ends_on', 'notes'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'sold_at' => 'datetime',
            'warranty_ends_on' => 'date',
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
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<InventorySerialMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventorySerialMovement::class);
    }
}
