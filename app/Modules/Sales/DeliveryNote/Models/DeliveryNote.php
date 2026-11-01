<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Models;

use App\Models\User;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\DeliveryNote\Enums\DeliveryNoteStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
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
 * Goods handed to the customer. Posting moves the stock and books the cost of sales;
 * invoices made from it then book only the revenue.
 *
 * @property string $id
 * @property int $branch_id
 * @property string|null $delivery_number
 * @property string|null $sales_order_id
 * @property string $customer_id
 * @property int $warehouse_id
 * @property Carbon $delivery_date
 * @property array<string, mixed>|null $shipping_address
 * @property DeliveryNoteStatus $status
 * @property string|null $notes
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'branch_id', 'delivery_number', 'sales_order_id', 'customer_id', 'warehouse_id', 'delivery_date',
    'shipping_address', 'status', 'notes', 'created_by', 'posted_by', 'posted_at',
])]
class DeliveryNote extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'delivery_note';

    /**
     * Order -> delivery note keeps the order's branch when the warehouse does not decide it.
     */
    public function documentBranchFromParent(): ?int
    {
        return $this->branchOfDocument(SalesOrder::class, $this->sales_order_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeliveryNoteStatus::class,
            'delivery_date' => 'date',
            'shipping_address' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return HasMany<DeliveryNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
