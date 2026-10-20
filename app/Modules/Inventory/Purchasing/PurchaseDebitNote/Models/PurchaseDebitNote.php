<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteStatus;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property PurchaseDebitNoteStatus $status
 * @property PurchaseDebitNoteType $type
 * @property Carbon|null $debit_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'debit_note_number',
    'purchase_invoice_id',
    'supplier_id',
    'currency_id',
    'exchange_rate',
    'type',
    'debit_date',
    'reason',
    'notes',
    'subtotal',
    'discount_total',
    'tax_total',
    'adjustment',
    'grand_total',
    'applied_total',
    'refunded_total',
    'open_credit',
    'status',
    'created_by',
    'posted_by',
    'posted_at',
])]
class PurchaseDebitNote extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'purchase_debit_note';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseDebitNoteStatus::class,
            'type' => PurchaseDebitNoteType::class,
            'debit_date' => 'date',
            'exchange_rate' => 'decimal:12',
            'subtotal' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'adjustment' => 'decimal:4',
            'grand_total' => 'decimal:4',
            'applied_total' => 'decimal:4',
            'refunded_total' => 'decimal:4',
            'open_credit' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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
     * @return HasMany<PurchaseDebitNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseDebitNoteLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<PurchaseDebitNoteAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseDebitNoteAllocation::class)->orderBy('id');
    }
}
