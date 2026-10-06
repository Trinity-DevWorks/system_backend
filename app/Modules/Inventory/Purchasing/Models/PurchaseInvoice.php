<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Models;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
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
 * @property PurchaseInvoiceStatus $status
 * @property Carbon|null $invoice_date
 * @property Carbon|null $due_on
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'invoice_number',
    'supplier_id',
    'goods_receipt_id',
    'purchase_order_id',
    'warehouse_id',
    'currency_id',
    'payment_method_id',
    'payment_terms_id',
    'status',
    'invoice_date',
    'due_on',
    'exchange_rate',
    'reference_2',
    'subtotal',
    'discount_total',
    'tax_total',
    'adjustment',
    'grand_total',
    'paid_total',
    'net_to_pay',
    'notes',
    'replaces_invoice_id',
    'linked_proof_id',
    'linked_seal',
    'linked_dispute_reason',
    'created_by',
    'posted_by',
    'posted_at',
])]
class PurchaseInvoice extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'purchase_invoice';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseInvoiceStatus::class,
            'linked_seal' => 'array',
            'invoice_date' => 'date',
            'due_on' => 'date',
            'exchange_rate' => 'decimal:12',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
        return $this->belongsTo(PaymentTerm::class);
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
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function replacesInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_invoice_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne<PurchaseInvoice, $this>
     */
    public function replacedByInvoice(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'replaces_invoice_id');
    }

    /**
     * @return HasMany<PurchaseInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseInvoiceLine::class);
    }
}
