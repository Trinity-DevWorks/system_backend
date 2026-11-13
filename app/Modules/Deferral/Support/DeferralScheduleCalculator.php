<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Support;

use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Enums\DeferralMethod;
use App\Modules\Deferral\Enums\DeferralPostingDay;
use App\Modules\Deferral\Models\DeferralSchedule;
use Illuminate\Support\Carbon;

/**
 * Spreads a total across the calendar months a period touches.
 * Cumulative rounding keeps the sum exact: the last line is the remainder.
 */
final class DeferralScheduleCalculator
{
    /**
     * @return list<array{line_no: int, period_start: string, period_end: string, posting_date: string, amount: string, amount_primary: string}>
     */
    public function build(
        string $total,
        string $totalPrimary,
        Carbon $start,
        Carbon $end,
        DeferralMethod $method,
        DeferralPostingDay $day,
    ): array {
        $start = $start->copy()->startOfDay();
        $end = $end->copy()->startOfDay();
        if ($end->lt($start)) {
            $this->fail('The end date cannot be before the start date.');
        }

        $periods = $this->periods($start, $end, $method);
        $max = (int) config('accounting.deferrals.max_periods', 120);
        if (count($periods) > $max) {
            $this->fail("A schedule cannot cover more than {$max} periods.");
        }
        if ($periods === []) {
            $this->fail('The deferral period does not cover any month.');
        }

        $weights = array_column($periods, 'weight');
        $amounts = $this->allocate(JournalMath::store($total), $weights);
        $primaries = $this->allocate(JournalMath::store($totalPrimary), $weights);

        $lines = [];
        foreach ($periods as $index => $period) {
            $posting = $day === DeferralPostingDay::PeriodStart ? $period['start'] : $period['end'];
            $lines[] = [
                'line_no' => $index + 1,
                'period_start' => $period['start'],
                'period_end' => $period['end'],
                'posting_date' => $posting,
                'amount' => $amounts[$index],
                'amount_primary' => $primaries[$index],
            ];
        }

        return $lines;
    }

    /**
     * Pending lines after the last posted period, numbered to continue the schedule.
     *
     * @return list<array{line_no: int, period_start: string, period_end: string, posting_date: string, amount: string, amount_primary: string}>
     */
    public function rebuildRemaining(DeferralSchedule $schedule, Carbon $newEnd, ?DeferralMethod $method = null): array
    {
        $lines = $schedule->relationLoaded('lines') ? $schedule->lines : $schedule->lines()->get();
        $posted = $lines->filter(fn ($line): bool => $line->status === DeferralLineStatus::Posted);
        $lastNo = (int) ($posted->max('line_no') ?? 0);
        $lastPosted = $posted->sortByDesc('line_no')->first();
        $start = $lastPosted !== null
            ? Carbon::parse($lastPosted->period_end)->addDay()->startOfDay()
            : Carbon::parse($schedule->start_date)->startOfDay();

        $remaining = JournalMath::store(JournalMath::sub((string) $schedule->total_amount, (string) $schedule->recognized_amount));
        $remainingPrimary = JournalMath::store(JournalMath::sub((string) $schedule->total_amount_primary, (string) $schedule->recognized_amount_primary));
        if (! JournalMath::isPositive($remaining, JournalMath::STORAGE_SCALE)) {
            $this->fail('There is no remaining amount to reschedule.');
        }

        $built = $this->build(
            $remaining,
            $remainingPrimary,
            $start,
            $newEnd->copy()->startOfDay(),
            $method ?? $schedule->method,
            $schedule->posting_day,
        );
        foreach ($built as $index => $row) {
            $built[$index]['line_no'] = $lastNo + $index + 1;
        }

        return $built;
    }

    /**
     * @return list<array{start: string, end: string, weight: string}>
     */
    private function periods(Carbon $start, Carbon $end, DeferralMethod $method): array
    {
        $periods = [];
        $cursor = $start->copy()->startOfMonth();
        $last = $end->copy()->startOfMonth();
        while ($cursor->lessThanOrEqualTo($last)) {
            $periodStart = $cursor->copy()->startOfMonth()->max($start)->startOfDay();
            $periodEnd = $cursor->copy()->endOfMonth()->min($end)->startOfDay();
            $weight = $method === DeferralMethod::DailyProrata
                ? (string) $this->inclusiveDays($periodStart, $periodEnd)
                : '1';
            $periods[] = [
                'start' => $periodStart->toDateString(),
                'end' => $periodEnd->toDateString(),
                'weight' => $weight,
            ];
            $cursor->addMonth();
        }

        return $periods;
    }

    /**
     * @param  list<string>  $weights
     * @return list<string>
     */
    private function allocate(string $total, array $weights): array
    {
        $count = count($weights);
        $weightTotal = '0';
        foreach ($weights as $weight) {
            $weightTotal = bcadd($weightTotal, $weight, 4);
        }
        if (bccomp($weightTotal, '0', 4) !== 1) {
            $this->fail('The deferral period has no days to allocate.');
        }

        $amounts = [];
        $cumulative = '0';
        $cumulativeWeight = '0';
        for ($i = 0; $i < $count - 1; $i++) {
            $cumulativeWeight = bcadd($cumulativeWeight, $weights[$i], 4);
            $raw = bcdiv(bcmul($total, $cumulativeWeight, 12), $weightTotal, 12);
            $cum = JournalMath::round($raw, JournalMath::STORAGE_SCALE);
            $amounts[] = JournalMath::store(JournalMath::sub($cum, $cumulative));
            $cumulative = $cum;
        }
        $amounts[] = JournalMath::store(JournalMath::sub($total, $cumulative));

        return $amounts;
    }

    private function inclusiveDays(Carbon $start, Carbon $end): int
    {
        return (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
    }

    private function fail(string $message): never
    {
        abort(422, $message, ['X-Error-Code' => 'DEFERRAL_INVALID_PERIOD']);
    }
}
