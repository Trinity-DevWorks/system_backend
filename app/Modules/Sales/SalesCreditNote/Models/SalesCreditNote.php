<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Models;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
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
 * @property SalesCreditNoteStatus $status
 * @property Carbon|null $credit_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'credit_note_number',
    'sales_invoice_id',
    'customer_id',
    'warehouse_id',
    'currency_id',
    'status',
    'credit_date',
    'exchange_rate',
    'subtotal',
    'discount_total',
    'tax_total',
    'grand_total',
    'notes',
    'created_by',
    'posted_by',
    'posted_at',
])]
class SalesCreditNote extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'sales_credit_note';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesCreditNoteStatus::class,
            'credit_date' => 'date',
            'exchange_rate' => 'decimal:12',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
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
     * @return HasMany<SalesCreditNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesCreditNoteLine::class)->orderBy('sort_order')->orderBy('id');
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
}
