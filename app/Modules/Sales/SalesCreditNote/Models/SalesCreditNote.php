<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Models;

use App\Models\User;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteType;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
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
 * @property SalesCreditNoteStatus $status
 * @property SalesCreditNoteType $type
 * @property Carbon|null $credit_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'branch_id',
    'credit_note_number',
    'sales_invoice_id',
    'customer_id',
    'currency_id',
    'exchange_rate',
    'type',
    'credit_date',
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
class SalesCreditNote extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    /**
     * No header warehouse: the branch follows the original invoice.
     *
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return ['branch_id' => null];
    }

    public function documentBranchFromParent(): ?int
    {
        $branchId = SalesInvoice::query()->whereKey($this->sales_invoice_id)->value('branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }

    public const REFERENCE_TYPE = 'sales_credit_note';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SalesCreditNoteStatus::class,
            'type' => SalesCreditNoteType::class,
            'credit_date' => 'date',
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
     * @return HasMany<SalesCreditNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesCreditNoteLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<SalesCreditNoteAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SalesCreditNoteAllocation::class)->orderBy('id');
    }
}
