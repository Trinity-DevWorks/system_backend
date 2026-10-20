<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Models\User;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property JournalEntryStatus $status
 * @property Carbon|null $entry_date
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'entry_number',
    'entry_date',
    'source_type',
    'source_id',
    'source_event',
    'reversal_of_id',
    'branch_id',
    'description',
    'status',
    'total_debit',
    'total_credit',
    'created_by',
    'posted_by',
    'posted_at',
])]
class JournalEntry extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_VAT_SETTLEMENT = 'vat_settlement';

    public const SOURCE_YEAR_END_CLOSE = 'year_end_close';

    public const EVENT_POST = 'post';

    public const EVENT_REVERSE = 'reverse';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JournalEntryStatus::class,
            'entry_date' => 'date',
            'branch_id' => 'integer',
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    public function isManual(): bool
    {
        return $this->source_type === self::SOURCE_MANUAL;
    }

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<JournalEntry, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
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
