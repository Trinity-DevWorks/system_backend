<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Models;

use App\Models\User;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Currency\Models\Currency;
use App\Modules\RecurringEntry\Enums\RateMode;
use App\Modules\RecurringEntry\Enums\RecurrenceFrequency;
use App\Modules\RecurringEntry\Enums\RecurringDocumentType;
use App\Modules\RecurringEntry\Enums\RecurringTemplateStatus;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Treasury\Models\CashBankAccount;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A schedule that generates a journal entry or an expense voucher.
 *
 * @property string $id
 * @property string|null $template_number
 * @property string $name
 * @property string|null $description
 * @property RecurringDocumentType $document_type
 * @property int $branch_id
 * @property int $currency_id
 * @property RateMode $rate_mode
 * @property string|null $fixed_exchange_rate
 * @property int|null $cash_bank_account_id
 * @property string|null $payee
 * @property string|null $supplier_id
 * @property string|null $reference
 * @property string|null $notes
 * @property RecurrenceFrequency $frequency
 * @property int $interval
 * @property int|null $day_of_month
 * @property int|null $day_of_week
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property int|null $max_occurrences
 * @property int $occurrences_count
 * @property int $next_occurrence_index
 * @property Carbon|null $next_run_date
 * @property Carbon|null $last_run_date
 * @property bool $auto_post
 * @property RecurringTemplateStatus $status
 * @property string|null $last_error_code
 * @property string|null $created_by
 */
#[Fillable([
    'template_number', 'name', 'description', 'document_type', 'branch_id', 'currency_id', 'rate_mode',
    'fixed_exchange_rate', 'cash_bank_account_id', 'payee', 'supplier_id', 'reference', 'notes',
    'frequency', 'interval', 'day_of_month', 'day_of_week', 'start_date', 'end_date', 'max_occurrences',
    'occurrences_count', 'next_occurrence_index', 'next_run_date', 'last_run_date', 'auto_post', 'status',
    'last_error_code', 'created_by', 'updated_by',
])]
class RecurringTemplate extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'recurring_template';

    /**
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return ['branch_id' => null];
    }

    public function documentBranchIsFrozen(): bool
    {
        return (int) $this->getOriginal('occurrences_count') > 0 || $this->status?->isTerminal() === true;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_type' => RecurringDocumentType::class,
            'rate_mode' => RateMode::class,
            'frequency' => RecurrenceFrequency::class,
            'status' => RecurringTemplateStatus::class,
            'fixed_exchange_rate' => 'decimal:12',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_run_date' => 'date',
            'last_run_date' => 'date',
            'auto_post' => 'boolean',
            'interval' => 'integer',
            'day_of_month' => 'integer',
            'day_of_week' => 'integer',
            'occurrences_count' => 'integer',
            'next_occurrence_index' => 'integer',
            'branch_id' => 'integer',
            'currency_id' => 'integer',
            'cash_bank_account_id' => 'integer',
        ];
    }

    /**
     * Document-currency total: voucher line amounts, or journal-entry debits.
     */
    public function totalAmount(): string
    {
        if (! $this->relationLoaded('lines')) {
            $this->load('lines');
        }
        $total = '0';
        foreach ($this->lines as $line) {
            $part = $this->document_type === RecurringDocumentType::ExpenseVoucher ? $line->amount : $line->debit;
            $total = JournalMath::add($total, $part);
        }

        return JournalMath::store($total);
    }

    /**
     * @return HasMany<RecurringTemplateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RecurringTemplateLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<RecurringRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(RecurringRun::class)->orderByDesc('scheduled_date')->orderByDesc('id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
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
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
