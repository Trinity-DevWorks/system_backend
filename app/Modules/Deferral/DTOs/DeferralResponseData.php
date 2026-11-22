<?php

declare(strict_types=1);

namespace App\Modules\Deferral\DTOs;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\SourceDocuments;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;

final class DeferralResponseData
{
    /**
     * @param  list<array{code: string, message: string}>  $warnings
     * @return array<string, mixed>
     */
    public static function schedule(DeferralSchedule $schedule, array $warnings = []): array
    {
        $data = self::scheduleRow($schedule);
        $lines = $schedule->relationLoaded('lines') ? $schedule->lines : collect();
        $data['lines'] = $lines->map(fn (DeferralScheduleLine $line): array => self::line($line))->values()->all();
        $data['warnings'] = $warnings;
        $data['supplier'] = $schedule->relationLoaded('supplier') && $schedule->supplier !== null
            ? ['id' => (string) $schedule->supplier->id, 'name' => $schedule->supplier->name]
            : null;
        $data['customer'] = $schedule->relationLoaded('customer') && $schedule->customer !== null
            ? ['id' => (string) $schedule->customer->id, 'name' => $schedule->customer->name]
            : null;
        $data['created_by'] = self::user($schedule->relationLoaded('createdByUser') ? $schedule->createdByUser : null);
        $data['activated_by'] = self::user($schedule->relationLoaded('activatedByUser') ? $schedule->activatedByUser : null);
        $data['activated_at'] = $schedule->activated_at?->toIso8601String();
        $data['cancellation_reason'] = $schedule->cancellation_reason;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public static function scheduleRow(DeferralSchedule $schedule): array
    {
        $next = null;
        $linesCount = $schedule->lines_count ?? null;
        $postedCount = $schedule->posted_lines_count ?? null;
        if ($schedule->relationLoaded('lines')) {
            $linesCount = $schedule->lines->count();
            $postedCount = $schedule->lines->where('status', DeferralLineStatus::Posted)->count();
            $pending = $schedule->lines
                ->filter(fn (DeferralScheduleLine $line): bool => $line->status === DeferralLineStatus::Pending)
                ->sortBy('posting_date')
                ->first();
            $next = $pending?->posting_date?->toDateString();
        } else {
            $pendingDate = $schedule->getAttribute('pending_posting_date');
            $next = $pendingDate !== null ? substr((string) $pendingDate, 0, 10) : null;
        }

        return [
            'id' => (string) $schedule->id,
            'schedule_number' => $schedule->schedule_number,
            'kind' => $schedule->kind->value,
            'name' => $schedule->name,
            'description' => $schedule->description,
            'branch' => $schedule->relationLoaded('branch') && $schedule->branch !== null
                ? ['id' => (int) $schedule->branch->id, 'name' => $schedule->branch->name]
                : null,
            'deferral_account' => self::account($schedule->relationLoaded('deferralAccount') ? $schedule->deferralAccount : null),
            'recognition_account' => self::account($schedule->relationLoaded('recognitionAccount') ? $schedule->recognitionAccount : null),
            'currency' => $schedule->relationLoaded('currency') && $schedule->currency !== null ? [
                'id' => (int) $schedule->currency->id,
                'code' => $schedule->currency->code,
                'symbol' => $schedule->currency->symbol,
            ] : null,
            'exchange_rate' => (string) $schedule->exchange_rate,
            'total_amount' => (string) $schedule->total_amount,
            'total_amount_primary' => (string) $schedule->total_amount_primary,
            'recognized_amount' => (string) $schedule->recognized_amount,
            'remaining_amount' => $schedule->remaining_amount,
            'start_date' => $schedule->start_date->toDateString(),
            'end_date' => $schedule->end_date->toDateString(),
            'method' => $schedule->method->value,
            'posting_day' => $schedule->posting_day->value,
            'auto_post' => (bool) $schedule->auto_post,
            'source' => self::source($schedule),
            'status' => $schedule->status->value,
            'next_posting_date' => $next,
            'lines_count' => (int) ($linesCount ?? 0),
            'posted_lines_count' => (int) ($postedCount ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function line(DeferralScheduleLine $line): array
    {
        $entry = $line->relationLoaded('journalEntry') ? $line->journalEntry : null;

        return [
            'id' => (int) $line->id,
            'line_no' => (int) $line->line_no,
            'period_start' => $line->period_start->toDateString(),
            'period_end' => $line->period_end->toDateString(),
            'posting_date' => $line->posting_date->toDateString(),
            'amount' => (string) $line->amount,
            'amount_primary' => (string) $line->amount_primary,
            'status' => $line->status->value,
            'journal_entry' => $entry !== null ? [
                'id' => (string) $entry->id,
                'entry_number' => $entry->entry_number,
            ] : null,
            'posted_at' => $line->posted_at?->toIso8601String(),
            'error_code' => $line->error_code,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private static function account(?Account $account): ?array
    {
        if ($account === null) {
            return null;
        }

        return ['id' => (int) $account->id, 'code' => (string) $account->code, 'name' => (string) $account->name];
    }

    /**
     * @return array{type: string, id: ?string, line_id: ?int, number: ?string}
     */
    private static function source(DeferralSchedule $schedule): array
    {
        $number = null;
        if ($schedule->source_id !== null && $schedule->source_id !== '') {
            $numbers = SourceDocuments::numbers([[$schedule->source_type->value, (string) $schedule->source_id]]);
            $number = $numbers[$schedule->source_type->value.':'.$schedule->source_id] ?? null;
        }

        return [
            'type' => $schedule->source_type->value,
            'id' => $schedule->source_id,
            'line_id' => $schedule->source_line_id !== null ? (int) $schedule->source_line_id : null,
            'number' => $number,
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return ['id' => (string) $user->id, 'name' => (string) $user->name];
    }
}
