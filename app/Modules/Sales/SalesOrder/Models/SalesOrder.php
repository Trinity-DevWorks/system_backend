<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Models;

use App\Models\User;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Salesman\Models\Salesman;
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
 * @property SalesOrderStatus $status
 * @property Carbon|null $order_date
 * @property Carbon|null $requested_date
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $cancelled_at
 */
#[Fillable([
    'branch_id',
    'order_number',
    'customer_id',
    'warehouse_id',
    'currency_id',
    'salesman_id',
    'payment_terms_id',
    'sales_quotation_id',
    'status',
    'order_date',
    'requested_date',
    'exchange_rate',
    'subtotal',
    'discount_total',
    'tax_total',
    'grand_total',
    'notes',
    'created_by',
    'confirmed_by',
    'confirmed_at',
    'cancelled_by',
    'cancelled_at',
])]
class SalesOrder extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    /**
     * Quotation → order keeps the quotation's branch when the warehouse does not decide it.
     */
    public function documentBranchFromParent(): ?int
    {
        return $this->branchOfDocument(SalesQuotation::class, $this->sales_quotation_id);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesOrderStatus::class,
            'order_date' => 'date',
            'requested_date' => 'date',
            'exchange_rate' => 'decimal:12',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
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
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Salesman, $this>
     */
    public function salesman(): BelongsTo
    {
        return $this->belongsTo(Salesman::class);
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_terms_id');
    }

    /**
     * @return BelongsTo<SalesQuotation, $this>
     */
    public function salesQuotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class);
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
    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<SalesOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<SalesInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SalesInvoice::class);
    }
}
