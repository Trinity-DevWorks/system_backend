<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Models;

use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Treasury\Enums\TreasuryDocumentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * An expense paid directly from a cash/bank account (rent, utilities, petty cash...).
 *
 * @property string $id
 * @property int $branch_id
 * @property string|null $voucher_number
 * @property Carbon $voucher_date
 * @property int $cash_bank_account_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string|null $supplier_id
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property TreasuryDocumentStatus $status
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'branch_id', 'voucher_number', 'voucher_date', 'cash_bank_account_id', 'currency_id', 'exchange_rate', 'payee',
    'supplier_id', 'reference', 'notes', 'subtotal', 'tax_total', 'total', 'status', 'created_by', 'posted_by', 'posted_at',
    'recurring_template_id',
])]
class ExpenseVoucher extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'expense_voucher';

    /**
     * No warehouse: the branch comes from the user's active branch.
     *
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return ['branch_id' => null];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voucher_date' => 'date',
            'exchange_rate' => 'decimal:12',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'status' => TreasuryDocumentStatus::class,
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CashBankAccount, $this>
     */
    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<ExpenseVoucherLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseVoucherLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<RecurringTemplate, $this>
     */
    public function recurringTemplate(): BelongsTo
    {
        return $this->belongsTo(RecurringTemplate::class);
    }
}
