<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Models;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $deferral_schedule_id
 * @property int $line_no
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property Carbon $posting_date
 * @property string $amount
 * @property string $amount_primary
 * @property DeferralLineStatus $status
 * @property string|null $journal_entry_id
 * @property string|null $posted_by
 * @property Carbon|null $posted_at
 * @property string|null $error_code
 * @property int $post_attempt
 */
#[Fillable([
    'deferral_schedule_id', 'line_no', 'period_start', 'period_end', 'posting_date', 'amount', 'amount_primary',
    'status', 'journal_entry_id', 'posted_by', 'posted_at', 'error_code', 'post_attempt',
])]
class DeferralScheduleLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'posting_date' => 'date',
            'amount' => 'decimal:4',
            'amount_primary' => 'decimal:4',
            'status' => DeferralLineStatus::class,
            'posted_at' => 'datetime',
            'line_no' => 'integer',
            'post_attempt' => 'integer',
        ];
    }

    /**
     * Source id of the recognition entry for the current attempt.
     * A reversed line is posted again under ":r{n}" so the unique source key stays free.
     */
    public function recognitionSourceId(string $scheduleId): string
    {
        $base = $scheduleId.':'.$this->line_no;
        $attempt = (int) $this->post_attempt;

        return $attempt > 0 ? $base.':r'.$attempt : $base;
    }

    /**
     * @return BelongsTo<DeferralSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DeferralSchedule::class, 'deferral_schedule_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
