<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Deferral\Enums\DeferralKind;
use App\Modules\Deferral\Enums\DeferralMethod;
use App\Modules\Deferral\Enums\DeferralPostingDay;
use App\Modules\Deferral\Enums\DeferralSourceType;
use App\Modules\Deferral\Enums\DeferralStatus;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Spreads a prepaid expense or deferred revenue balance over time.
 *
 * @property string $id
 * @property string|null $schedule_number
 * @property DeferralKind $kind
 * @property string $name
 * @property string|null $description
 * @property int $branch_id
 * @property int $deferral_account_id
 * @property int $recognition_account_id
 * @property int $currency_id
 * @property string $exchange_rate
 * @property string $total_amount
 * @property string $total_amount_primary
 * @property string $recognized_amount
 * @property string $recognized_amount_primary
 * @property string $remaining_amount
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property DeferralMethod $method
 * @property DeferralPostingDay $posting_day
 * @property bool $auto_post
 * @property DeferralSourceType $source_type
 * @property string|null $source_id
 * @property int|null $source_line_id
 * @property string|null $supplier_id
 * @property string|null $customer_id
 * @property DeferralStatus $status
 * @property string|null $created_by
 * @property string|null $activated_by
 * @property string|null $cancelled_by
 * @property Carbon|null $activated_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 */
#[Fillable([
    'schedule_number', 'kind', 'name', 'description', 'branch_id', 'deferral_account_id', 'recognition_account_id',
    'currency_id', 'exchange_rate', 'total_amount', 'total_amount_primary', 'recognized_amount', 'recognized_amount_primary',
    'start_date', 'end_date', 'method', 'posting_day', 'auto_post', 'source_type', 'source_id', 'source_line_id',
    'supplier_id', 'customer_id', 'status', 'created_by', 'activated_by', 'cancelled_by', 'activated_at', 'cancelled_at',
    'cancellation_reason',
])]
class DeferralSchedule extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'deferral_schedule';

    /**
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
            'kind' => DeferralKind::class,
            'method' => DeferralMethod::class,
            'posting_day' => DeferralPostingDay::class,
            'source_type' => DeferralSourceType::class,
            'status' => DeferralStatus::class,
            'exchange_rate' => 'decimal:12',
            'total_amount' => 'decimal:4',
            'total_amount_primary' => 'decimal:4',
            'recognized_amount' => 'decimal:4',
            'recognized_amount_primary' => 'decimal:4',
            'start_date' => 'date',
            'end_date' => 'date',
            'auto_post' => 'boolean',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'branch_id' => 'integer',
            'deferral_account_id' => 'integer',
            'recognition_account_id' => 'integer',
            'currency_id' => 'integer',
            'source_line_id' => 'integer',
        ];
    }

    /**
     * @return Attribute<string, never>
     */
    protected function remainingAmount(): Attribute
    {
        return Attribute::get(fn (): string => JournalMath::store(
            JournalMath::sub((string) $this->total_amount, (string) $this->recognized_amount)
        ));
    }

    /**
     * @return Attribute<string, never>
     */
    protected function remainingAmountPrimary(): Attribute
    {
        return Attribute::get(fn (): string => JournalMath::store(
            JournalMath::sub((string) $this->total_amount_primary, (string) $this->recognized_amount_primary)
        ));
    }

    /**
     * @return HasMany<DeferralScheduleLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DeferralScheduleLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function deferralAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deferral_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function recognitionAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'recognition_account_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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
    public function activatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
