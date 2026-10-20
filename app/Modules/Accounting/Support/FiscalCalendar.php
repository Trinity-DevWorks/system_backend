<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\CompanySetting\Models\CompanySetting;
use Illuminate\Support\Carbon;

/**
 * Fiscal year boundaries for report defaults. Changing the start month only
 * changes which dates a report selects; it does not move posted entries.
 */
final class FiscalCalendar
{
    public function today(): string
    {
        $timezone = (string) (CompanySetting::current()->timezone ?: 'UTC');

        try {
            return Carbon::now($timezone)->toDateString();
        } catch (\Throwable) {
            return Carbon::today()->toDateString();
        }
    }

    public function startMonth(): int
    {
        $month = (int) (CompanySetting::current()->fiscal_year_start_month ?? 1);

        return $month >= 1 && $month <= 12 ? $month : 1;
    }

    /**
     * First day of the fiscal year that contains `$asOf`.
     */
    public function fiscalYearStart(string $asOf): string
    {
        $date = Carbon::parse($asOf)->startOfDay();
        $start = Carbon::create($date->year, $this->startMonth(), 1)->startOfDay();
        if ($date->lt($start)) {
            $start->subYear();
        }

        return $start->toDateString();
    }

    /**
     * Last day of the fiscal year that contains `$asOf`.
     */
    public function fiscalYearEnd(string $asOf): string
    {
        return Carbon::parse($this->fiscalYearStart($asOf))->addYear()->subDay()->toDateString();
    }

    /**
     * @return array{starts_on: string, ends_on: string}
     */
    public function yearBounds(string $asOf): array
    {
        return [
            'starts_on' => $this->fiscalYearStart($asOf),
            'ends_on' => $this->fiscalYearEnd($asOf),
        ];
    }

    /**
     * Bounds when `$endsOn` is the last day of a fiscal year.
     *
     * @return array{starts_on: string, ends_on: string}|null
     */
    public function yearEndingOn(string $endsOn): ?array
    {
        $bounds = $this->yearBounds($endsOn);

        return $bounds['ends_on'] === $endsOn ? $bounds : null;
    }

    /**
     * @return array{from: string, to: string}
     */
    public function defaultPeriod(): array
    {
        $today = $this->today();

        return ['from' => $this->fiscalYearStart($today), 'to' => $today];
    }

    /**
     * Same length, ending the day before `$from`.
     *
     * @return array{from: string, to: string}
     */
    public function previousPeriod(string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        $days = (int) $start->diffInDays($end);
        $previousEnd = $start->copy()->subDay();

        return [
            'from' => $previousEnd->copy()->subDays($days)->toDateString(),
            'to' => $previousEnd->toDateString(),
        ];
    }

    /**
     * @return array{from: string, to: string}
     */
    public function previousYear(string $from, string $to): array
    {
        return [
            'from' => Carbon::parse($from)->subYear()->toDateString(),
            'to' => Carbon::parse($to)->subYear()->toDateString(),
        ];
    }
}
