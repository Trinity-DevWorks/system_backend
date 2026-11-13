<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Support;

use App\Modules\RecurringEntry\Enums\RecurrenceFrequency;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use Illuminate\Support\Carbon;

/**
 * Occurrence dates are always computed from the anchor (start date + n × step),
 * never by walking from the previous date. Day 31 therefore returns in March
 * after a short February.
 */
final class RecurrenceCalculator
{
    /**
     * @param  RecurringTemplate|array<string, mixed>  $rule
     */
    public static function occurrence(RecurringTemplate|array $rule, int $n): Carbon
    {
        $rule = self::normalize($rule);
        self::assertValid($rule);
        if ($n < 0) {
            self::fail('Occurrence index cannot be negative.');
        }

        $anchor = Carbon::parse($rule['start_date'])->startOfDay();
        $interval = (int) $rule['interval'];
        $frequency = RecurrenceFrequency::from($rule['frequency']);

        if ($frequency === RecurrenceFrequency::Daily) {
            return $anchor->copy()->addDays($n * $interval);
        }

        if ($frequency === RecurrenceFrequency::Weekly) {
            $first = self::firstWeekdayOnOrAfter($anchor, (int) $rule['day_of_week']);

            return $first->addWeeks($n * $interval);
        }

        $months = (int) $frequency->monthsPerStep() * $interval * $n;
        $month = $anchor->copy()->startOfMonth()->addMonthsNoOverflow($months);
        $day = $rule['day_of_month'] !== null ? (int) $rule['day_of_month'] : $anchor->day;
        if ($day === -1) {
            return $month->endOfMonth()->startOfDay();
        }

        return $month->day(min($day, $month->daysInMonth))->startOfDay();
    }

    /**
     * Smallest slot at or after $fromIndex whose date is on or after $date.
     *
     * @param  RecurringTemplate|array<string, mixed>  $rule
     * @return array{n: int, date: Carbon}|null
     */
    public static function firstOnOrAfter(RecurringTemplate|array $rule, Carbon $date, int $fromIndex = 0): ?array
    {
        $rule = self::normalize($rule);
        self::assertValid($rule);
        $target = $date->copy()->startOfDay();
        $index = max(0, $fromIndex);

        for ($guard = 0; $guard < 20000; $guard++) {
            if (self::pastEnd($rule, $index)) {
                return null;
            }
            $occurrence = self::occurrence($rule, $index);
            if ($occurrence->greaterThanOrEqualTo($target)) {
                return ['n' => $index, 'date' => $occurrence];
            }
            $index++;
        }

        return null;
    }

    /**
     * Date of slot $occurrencesCount when that many successful runs already exist
     * and nothing has been skipped. Null once the template is finished.
     *
     * @param  RecurringTemplate|array<string, mixed>  $rule
     */
    public static function nextAfter(RecurringTemplate|array $rule, int $occurrencesCount): ?Carbon
    {
        $rule = self::normalize($rule);
        self::assertValid($rule);
        if ($rule['max_occurrences'] !== null && $occurrencesCount >= (int) $rule['max_occurrences']) {
            return null;
        }
        if (self::pastEnd($rule, $occurrencesCount)) {
            return null;
        }

        return self::occurrence($rule, $occurrencesCount);
    }

    /**
     * @param  RecurringTemplate|array<string, mixed>  $rule
     * @return list<Carbon>
     */
    public static function preview(RecurringTemplate|array $rule, int $count, int $fromIndex = 0): array
    {
        $rule = self::normalize($rule);
        self::assertValid($rule);
        $dates = [];
        $index = max(0, $fromIndex);
        $wanted = max(0, $count);
        while (count($dates) < $wanted) {
            if ($rule['max_occurrences'] !== null && $index >= (int) $rule['max_occurrences']) {
                break;
            }
            if (self::pastEnd($rule, $index)) {
                break;
            }
            $dates[] = self::occurrence($rule, $index);
            $index++;
        }

        return $dates;
    }

    /**
     * Next run date for a template, or null when it has ended.
     */
    public static function nextRunDate(RecurringTemplate $template): ?Carbon
    {
        $rule = self::normalize($template);
        if ($rule['max_occurrences'] !== null && (int) $template->occurrences_count >= (int) $rule['max_occurrences']) {
            return null;
        }
        $index = (int) $template->next_occurrence_index;
        if (self::pastEnd($rule, $index)) {
            return null;
        }

        return self::occurrence($rule, $index);
    }

    /**
     * @param  RecurringTemplate|array<string, mixed>  $rule
     */
    public static function assertValid(RecurringTemplate|array $rule): void
    {
        $rule = self::normalize($rule);
        $frequency = RecurrenceFrequency::tryFrom((string) $rule['frequency']);
        if ($frequency === null) {
            self::fail('Choose a recurrence frequency.');
        }
        $interval = (int) $rule['interval'];
        if ($interval < 1 || $interval > 12) {
            self::fail('The interval must be between 1 and 12.');
        }
        if ($rule['end_date'] !== null && $rule['end_date'] < $rule['start_date']) {
            self::fail('The end date cannot be before the start date.');
        }

        $dayOfMonth = $rule['day_of_month'];
        $dayOfWeek = $rule['day_of_week'];
        if ($frequency === RecurrenceFrequency::Weekly) {
            if ($dayOfWeek === null || (int) $dayOfWeek < 1 || (int) $dayOfWeek > 7) {
                self::fail('A weekly recurrence needs a day of the week.');
            }
            if ($dayOfMonth !== null) {
                self::fail('A weekly recurrence cannot set a day of the month.');
            }

            return;
        }
        if ($dayOfWeek !== null) {
            self::fail('Only a weekly recurrence uses a day of the week.');
        }
        if ($frequency === RecurrenceFrequency::Daily) {
            if ($dayOfMonth !== null) {
                self::fail('A daily recurrence cannot set a day of the month.');
            }

            return;
        }
        if ($dayOfMonth !== null && (int) $dayOfMonth !== -1 && ((int) $dayOfMonth < 1 || (int) $dayOfMonth > 31)) {
            self::fail('The day of the month must be from 1 to 31, or the last day.');
        }
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private static function pastEnd(array $rule, int $index): bool
    {
        if ($rule['end_date'] === null) {
            return false;
        }

        return self::occurrence($rule, $index)->toDateString() > $rule['end_date'];
    }

    private static function firstWeekdayOnOrAfter(Carbon $anchor, int $dayOfWeek): Carbon
    {
        $delta = ($dayOfWeek - $anchor->dayOfWeekIso + 7) % 7;

        return $anchor->copy()->addDays($delta);
    }

    /**
     * @param  RecurringTemplate|array<string, mixed>  $rule
     * @return array{frequency: string, interval: int, day_of_month: ?int, day_of_week: ?int, start_date: string, end_date: ?string, max_occurrences: ?int}
     */
    public static function normalize(RecurringTemplate|array $rule): array
    {
        if ($rule instanceof RecurringTemplate) {
            return [
                'frequency' => $rule->frequency->value,
                'interval' => (int) $rule->interval,
                'day_of_month' => $rule->day_of_month !== null ? (int) $rule->day_of_month : null,
                'day_of_week' => $rule->day_of_week !== null ? (int) $rule->day_of_week : null,
                'start_date' => $rule->start_date->toDateString(),
                'end_date' => $rule->end_date?->toDateString(),
                'max_occurrences' => $rule->max_occurrences !== null ? (int) $rule->max_occurrences : null,
            ];
        }

        $end = $rule['end_date'] ?? null;
        $max = $rule['max_occurrences'] ?? null;

        return [
            'frequency' => (string) ($rule['frequency'] ?? ''),
            'interval' => (int) ($rule['interval'] ?? 1),
            'day_of_month' => isset($rule['day_of_month']) && $rule['day_of_month'] !== '' && $rule['day_of_month'] !== null
                ? (int) $rule['day_of_month'] : null,
            'day_of_week' => isset($rule['day_of_week']) && $rule['day_of_week'] !== '' && $rule['day_of_week'] !== null
                ? (int) $rule['day_of_week'] : null,
            'start_date' => substr((string) ($rule['start_date'] ?? ''), 0, 10),
            'end_date' => $end !== null && $end !== '' ? substr((string) $end, 0, 10) : null,
            'max_occurrences' => $max !== null && $max !== '' ? (int) $max : null,
        ];
    }

    private static function fail(string $message): never
    {
        abort(422, $message, ['X-Error-Code' => 'RECURRING_TEMPLATE_INVALID_SCHEDULE']);
    }
}
