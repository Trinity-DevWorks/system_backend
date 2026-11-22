<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Enums\DeferralStatus;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Support\TenantClock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class DeferralPostingService
{
    public function __construct(
        private readonly JournalPostingService $journalPosting,
        private readonly DeferralScheduleService $schedules,
        private readonly DomainNotificationPublisher $notifications,
    ) {}

    public function postLine(DeferralSchedule $schedule, DeferralScheduleLine $line, ?string $userId): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $line, $userId): DeferralSchedule {
            $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $lockedLine = DeferralScheduleLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ((string) $lockedLine->deferral_schedule_id !== (string) $locked->id) {
                abort(404);
            }
            if ($locked->status !== DeferralStatus::Active) {
                abort(422, 'Only an active schedule can post a recognition line.', ['X-Error-Code' => 'DEFERRAL_NOT_ACTIVE']);
            }
            if ($lockedLine->status !== DeferralLineStatus::Pending) {
                abort(422, 'Only a pending line can be posted.', ['X-Error-Code' => 'DEFERRAL_LINE_NOT_PENDING']);
            }
            $earlier = DeferralScheduleLine::query()
                ->where('deferral_schedule_id', $locked->id)
                ->where('status', DeferralLineStatus::Pending->value)
                ->where('line_no', '<', $lockedLine->line_no)
                ->exists();
            if ($earlier) {
                abort(422, 'Post the earlier pending line first.', ['X-Error-Code' => 'DEFERRAL_LINE_OUT_OF_ORDER']);
            }

            // A line number can be reused (reschedule, recognise remaining) after an earlier entry with
            // the same source id was posted and reversed. Move to the next free attempt so the new
            // entry never collides with the old one.
            while (JournalEntry::query()
                ->where('source_type', SourceDocuments::DEFERRAL_RECOGNITION)
                ->where('source_id', $lockedLine->recognitionSourceId((string) $locked->id))
                ->exists()) {
                $lockedLine->post_attempt = (int) $lockedLine->post_attempt + 1;
            }
            if ($lockedLine->isDirty('post_attempt')) {
                $lockedLine->save();
            }

            $entry = $this->journalPosting->deferralRecognition($locked, $lockedLine, $userId);
            if ($entry === null) {
                abort(422, 'The recognition entry was not posted.', ['X-Error-Code' => 'DEFERRAL_LINE_NOT_PENDING']);
            }
            $lockedLine->update([
                'status' => DeferralLineStatus::Posted,
                'journal_entry_id' => $entry->id,
                'posted_by' => $userId,
                'posted_at' => now(),
                'error_code' => null,
            ]);
            $this->schedules->refreshTotals($locked);

            return $this->schedules->find((string) $locked->id);
        });
    }

    public function postDue(DeferralSchedule $schedule, Carbon $asOf, ?string $userId): int
    {
        $posted = 0;
        $lines = DeferralScheduleLine::query()
            ->where('deferral_schedule_id', $schedule->id)
            ->where('status', DeferralLineStatus::Pending->value)
            ->whereDate('posting_date', '<=', $asOf->toDateString())
            ->orderBy('line_no')
            ->get();
        foreach ($lines as $line) {
            try {
                $this->postLine($schedule, $line, $userId);
                $posted++;
            } catch (Throwable $exception) {
                $fresh = DeferralScheduleLine::query()->find($line->id);
                if ($fresh !== null && $fresh->status === DeferralLineStatus::Posted) {
                    $posted++;

                    continue;
                }
                $code = $exception instanceof HttpExceptionInterface
                    ? (string) ($exception->getHeaders()['X-Error-Code'] ?? 'DEFERRAL_POSTING_FAILED')
                    : 'DEFERRAL_POSTING_FAILED';
                DeferralScheduleLine::query()->whereKey($line->id)->update([
                    'error_code' => mb_substr($code !== '' ? $code : 'DEFERRAL_POSTING_FAILED', 0, 64),
                ]);

                throw $exception;
            }
        }

        return $posted;
    }

    /**
     * @return array{posted: int, failed: int}
     */
    public function postAllDue(?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? TenantClock::today())->copy()->startOfDay();
        $ids = DeferralSchedule::query()
            ->where('status', DeferralStatus::Active)
            ->where('auto_post', true)
            ->whereHas('lines', fn ($q) => $q
                ->where('status', DeferralLineStatus::Pending->value)
                ->whereDate('posting_date', '<=', $asOf->toDateString()))
            ->orderBy('id')
            ->pluck('id');

        $posted = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $schedule = DeferralSchedule::query()->find($id);
                if ($schedule === null || $schedule->status !== DeferralStatus::Active || ! $schedule->auto_post) {
                    continue;
                }
                $posted += $this->postDue($schedule, $asOf, null);
            } catch (Throwable $exception) {
                $failed++;
                $schedule = DeferralSchedule::query()->with('lines')->find($id);
                $line = $schedule?->lines
                    ->first(fn (DeferralScheduleLine $row): bool => $row->status === DeferralLineStatus::Pending && $row->error_code !== null);
                if ($schedule !== null && $line !== null) {
                    $code = $line->error_code ?? 'DEFERRAL_POSTING_FAILED';

                    try {
                        $this->notifications->deferralPostingFailed($schedule, $line, (string) $code);
                    } catch (Throwable) {
                        // Keep going with the next schedule.
                    }
                }
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    /**
     * Reversal is dated today. A closed original period stays closed; the reversing
     * entry lands in the open period, which is the usual correction.
     */
    public function reverseLine(DeferralSchedule $schedule, DeferralScheduleLine $line, ?string $userId): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $line, $userId): DeferralSchedule {
            $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $lockedLine = DeferralScheduleLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();
            if ((string) $lockedLine->deferral_schedule_id !== (string) $locked->id) {
                abort(404);
            }
            if ($lockedLine->status !== DeferralLineStatus::Posted) {
                abort(422, 'Only a posted line can be reversed.', ['X-Error-Code' => 'DEFERRAL_LINE_NOT_POSTED']);
            }
            $later = DeferralScheduleLine::query()
                ->where('deferral_schedule_id', $locked->id)
                ->where('status', DeferralLineStatus::Posted->value)
                ->where('line_no', '>', $lockedLine->line_no)
                ->exists();
            if ($later) {
                abort(422, 'Reverse the latest posted line first.', ['X-Error-Code' => 'DEFERRAL_LINE_OUT_OF_ORDER']);
            }

            $this->journalPosting->reverseDeferralRecognition($locked, $lockedLine, $userId, TenantClock::today()->toDateString());
            $lockedLine->update([
                'status' => DeferralLineStatus::Pending,
                'journal_entry_id' => null,
                'posted_by' => null,
                'posted_at' => null,
                'post_attempt' => (int) $lockedLine->post_attempt + 1,
                'error_code' => null,
            ]);
            $this->schedules->refreshTotals($locked);

            return $this->schedules->find((string) $locked->id);
        });
    }

    public function recognizeRemaining(DeferralSchedule $schedule, Carbon $date, ?string $userId): DeferralSchedule
    {
        return DB::transaction(function () use ($schedule, $date, $userId): DeferralSchedule {
            $locked = DeferralSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== DeferralStatus::Active) {
                abort(422, 'Only an active schedule can recognise the remaining balance.', ['X-Error-Code' => 'DEFERRAL_NOT_ACTIVE']);
            }
            $this->schedules->refreshTotals($locked);
            $locked->refresh();
            if (! JournalMath::isPositive($locked->remaining_amount)) {
                abort(422, 'There is no remaining amount to recognise.', ['X-Error-Code' => 'DEFERRAL_LINE_NOT_PENDING']);
            }
            $locked->lines()->where('status', DeferralLineStatus::Pending->value)->update([
                'status' => DeferralLineStatus::Cancelled->value,
            ]);
            $lineNo = (int) DeferralScheduleLine::query()->where('deferral_schedule_id', $locked->id)->max('line_no') + 1;
            $line = DeferralScheduleLine::query()->create([
                'deferral_schedule_id' => $locked->id,
                'line_no' => $lineNo,
                'period_start' => $date->toDateString(),
                'period_end' => $date->toDateString(),
                'posting_date' => $date->toDateString(),
                'amount' => $locked->remaining_amount,
                'amount_primary' => $locked->remaining_amount_primary,
                'status' => DeferralLineStatus::Pending,
            ]);

            return $this->postLine($locked, $line, $userId);
        });
    }
}
