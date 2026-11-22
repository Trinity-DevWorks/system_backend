<?php

declare(strict_types=1);

namespace Tests\Unit\RecurringEntry;

use App\Modules\RecurringEntry\Support\RecurrenceCalculator;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RecurrenceCalculatorTest extends TestCase
{
    public function test_monthly_day_31_returns_in_march_after_february(): void
    {
        $leap = $this->monthly('2024-01-31', 31);
        $this->assertSame('2024-01-31', RecurrenceCalculator::occurrence($leap, 0)->toDateString());
        $this->assertSame('2024-02-29', RecurrenceCalculator::occurrence($leap, 1)->toDateString());
        $this->assertSame('2024-03-31', RecurrenceCalculator::occurrence($leap, 2)->toDateString());

        $common = $this->monthly('2023-01-31', 31);
        $this->assertSame('2023-02-28', RecurrenceCalculator::occurrence($common, 1)->toDateString());
        $this->assertSame('2023-03-31', RecurrenceCalculator::occurrence($common, 2)->toDateString());
    }

    public function test_last_day_of_month(): void
    {
        $rule = $this->monthly('2026-01-15', -1);
        $this->assertSame('2026-01-31', RecurrenceCalculator::occurrence($rule, 0)->toDateString());
        $this->assertSame('2026-02-28', RecurrenceCalculator::occurrence($rule, 1)->toDateString());
        $this->assertSame('2026-04-30', RecurrenceCalculator::occurrence($rule, 3)->toDateString());
    }

    public function test_quarterly_from_30_november_and_interval_of_two(): void
    {
        $quarterly = $this->monthly('2025-11-30', 30, 'quarterly');
        $this->assertSame('2025-11-30', RecurrenceCalculator::occurrence($quarterly, 0)->toDateString());
        $this->assertSame('2026-02-28', RecurrenceCalculator::occurrence($quarterly, 1)->toDateString());
        $this->assertSame('2026-05-30', RecurrenceCalculator::occurrence($quarterly, 2)->toDateString());

        $everyTwo = $this->monthly('2026-01-31', 31, 'monthly', 2);
        $this->assertSame('2026-03-31', RecurrenceCalculator::occurrence($everyTwo, 1)->toDateString());
    }

    public function test_weekly_starts_on_the_requested_weekday(): void
    {
        $rule = [
            'frequency' => 'weekly',
            'interval' => 1,
            'day_of_week' => 1,
            'start_date' => '2026-01-07',
            'end_date' => null,
            'max_occurrences' => null,
        ];
        $this->assertSame('2026-01-12', RecurrenceCalculator::occurrence($rule, 0)->toDateString());
        $this->assertSame('2026-01-19', RecurrenceCalculator::occurrence($rule, 1)->toDateString());
    }

    public function test_end_date_and_max_occurrences_stop_the_series(): void
    {
        $byDate = $this->monthly('2026-01-01', 1);
        $byDate['end_date'] = '2026-03-15';
        $this->assertSame('2026-03-01', RecurrenceCalculator::nextAfter($byDate, 2)?->toDateString());
        $this->assertNull(RecurrenceCalculator::nextAfter($byDate, 3));

        $byCount = $this->monthly('2026-01-01', 1);
        $byCount['max_occurrences'] = 3;
        $this->assertNotNull(RecurrenceCalculator::nextAfter($byCount, 2));
        $this->assertNull(RecurrenceCalculator::nextAfter($byCount, 3));
        $this->assertCount(3, RecurrenceCalculator::preview($byCount, 12));
    }

    public function test_yearly_from_29_february_clamps_then_returns(): void
    {
        $rule = $this->monthly('2024-02-29', 29, 'yearly');
        $this->assertSame('2024-02-29', RecurrenceCalculator::occurrence($rule, 0)->toDateString());
        $this->assertSame('2025-02-28', RecurrenceCalculator::occurrence($rule, 1)->toDateString());
        $this->assertSame('2028-02-29', RecurrenceCalculator::occurrence($rule, 4)->toDateString());
    }

    public function test_invalid_combinations_are_rejected(): void
    {
        $this->expectException(HttpException::class);
        RecurrenceCalculator::assertValid([
            'frequency' => 'weekly',
            'interval' => 1,
            'day_of_week' => null,
            'start_date' => '2026-01-01',
            'end_date' => null,
            'max_occurrences' => null,
        ]);
    }

    public function test_first_on_or_after_does_not_move_backwards(): void
    {
        $rule = $this->monthly('2026-01-01', 1);
        $slot = RecurrenceCalculator::firstOnOrAfter($rule, Carbon::parse('2026-03-10'), 1);
        $this->assertNotNull($slot);
        $this->assertSame(3, $slot['n']);
        $this->assertSame('2026-04-01', $slot['date']->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    private function monthly(string $start, int $day, string $frequency = 'monthly', int $interval = 1): array
    {
        return [
            'frequency' => $frequency,
            'interval' => $interval,
            'day_of_month' => $day,
            'day_of_week' => null,
            'start_date' => $start,
            'end_date' => null,
            'max_occurrences' => null,
        ];
    }
}
