<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Models;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
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
 * @property SalesInvoiceStatus $status
 * @property Carbon|null $invoice_date
 * @property Carbon|null $due_on
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'invoice_number',
    'customer_id',
    'warehouse_id',
    'currency_id',
    'salesman_id',
    'payment_method_id',
    'payment_terms_id',
    'status',
    'invoice_date',
    'due_on',
    'exchange_rate',
    'reference_2',
    'billing_address',
    'shipping_address',
    'subtotal',
    'discount_total',
    'tax_total',
    'adjustment',
    'grand_total',
    'paid_total',
    'net_to_pay',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class SalesInvoice extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'sales_invoice';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesInvoiceStatus::class,
            'invoice_date' => 'date',
            'due_on' => 'date',
            'exchange_rate' => 'decimal:6',
            'billing_address' => 'array',
            'shipping_address' => 'array',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'adjustment' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'net_to_pay' => 'decimal:4',
            'posted_at' => 'datetime',
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
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return BelongsTo<PaymentTerm, $this>
     */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_terms_id');
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
     * @return HasMany<SalesInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesInvoiceLine::class)->orderBy('sort_order')->orderBy('id');
    }
}
