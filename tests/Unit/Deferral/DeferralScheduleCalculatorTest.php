<?php

declare(strict_types=1);

namespace Tests\Unit\Deferral;

use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Enums\DeferralMethod;
use App\Modules\Deferral\Enums\DeferralPostingDay;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;
use App\Modules\Deferral\Support\DeferralScheduleCalculator;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DeferralScheduleCalculatorTest extends TestCase
{
    private DeferralScheduleCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DeferralScheduleCalculator;
    }

    public function test_twelve_full_months_split_evenly(): void
    {
        $lines = $this->calculator->build(
            '12000.0000',
            '12000.0000',
            Carbon::parse('2027-01-01'),
            Carbon::parse('2027-12-31'),
            DeferralMethod::StraightLineMonthly,
            DeferralPostingDay::PeriodEnd,
        );

        $this->assertCount(12, $lines);
        $this->assertSame('1000.0000', $lines[0]['amount']);
        $this->assertSame('2027-01-31', $lines[0]['posting_date']);
        $this->assertSame('2027-12-31', $lines[11]['posting_date']);
        $this->assertSame('12000.0000', $this->sum($lines, 'amount'));
    }

    public function test_daily_prorata_from_15_january_sums_exactly(): void
    {
        $lines = $this->calculator->build(
            '1200.0000',
            '1200.0000',
            Carbon::parse('2026-01-15'),
            Carbon::parse('2027-01-14'),
            DeferralMethod::DailyProrata,
            DeferralPostingDay::PeriodEnd,
        );

        $this->assertSame('2026-01-15', $lines[0]['period_start']);
        $this->assertSame('2026-01-31', $lines[0]['period_end']);
        $this->assertSame(17, (int) Carbon::parse($lines[0]['period_start'])->diffInDays(Carbon::parse($lines[0]['period_end'])) + 1);
        $last = $lines[array_key_last($lines)];
        $this->assertSame('2027-01-01', $last['period_start']);
        $this->assertSame('2027-01-14', $last['period_end']);
        $this->assertSame(14, (int) Carbon::parse($last['period_start'])->diffInDays(Carbon::parse($last['period_end'])) + 1);
        $this->assertSame('1200.0000', $this->sum($lines, 'amount'));
    }

    public function test_cumulative_rounding_sums_to_the_total(): void
    {
        $lines = $this->calculator->build(
            '100.0000',
            '100.0000',
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
            DeferralMethod::StraightLineMonthly,
            DeferralPostingDay::PeriodStart,
        );

        $this->assertCount(3, $lines);
        $this->assertSame('2026-01-01', $lines[0]['posting_date']);
        $this->assertSame('100.0000', $this->sum($lines, 'amount'));
    }

    public function test_primary_amounts_at_a_large_rate_sum_exactly(): void
    {
        $primary = JournalMath::round(ExchangeRateSnapshot::toPrimary('1200.0000', '89500.000000000000', JournalMath::WORK_SCALE), JournalMath::STORAGE_SCALE);
        $lines = $this->calculator->build(
            '1200.0000',
            JournalMath::store($primary),
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-12-31'),
            DeferralMethod::StraightLineMonthly,
            DeferralPostingDay::PeriodEnd,
        );

        $this->assertSame(JournalMath::store($primary), $this->sum($lines, 'amount_primary'));
    }

    public function test_more_than_the_maximum_number_of_periods_is_rejected(): void
    {
        config(['accounting.deferrals.max_periods' => 2]);
        $this->expectException(HttpException::class);
        $this->calculator->build(
            '300.0000',
            '300.0000',
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-03-31'),
            DeferralMethod::StraightLineMonthly,
            DeferralPostingDay::PeriodEnd,
        );
    }

    public function test_rebuild_remaining_continues_after_posted_lines(): void
    {
        $schedule = new DeferralSchedule([
            'total_amount' => '12000.0000',
            'total_amount_primary' => '12000.0000',
            'recognized_amount' => '2000.0000',
            'recognized_amount_primary' => '2000.0000',
            'start_date' => '2027-01-01',
            'method' => DeferralMethod::StraightLineMonthly,
            'posting_day' => DeferralPostingDay::PeriodEnd,
        ]);
        $posted = collect([
            $this->postedLine(1, '2027-01-31'),
            $this->postedLine(2, '2027-02-28'),
        ]);
        $schedule->setRelation('lines', $posted);

        $lines = $this->calculator->rebuildRemaining($schedule, Carbon::parse('2027-06-30'));

        $this->assertSame(3, $lines[0]['line_no']);
        $this->assertSame('2027-03-01', $lines[0]['period_start']);
        $this->assertSame('10000.0000', $this->sum($lines, 'amount'));
        $this->assertSame('2027-06-30', $lines[array_key_last($lines)]['period_end']);
    }

    private function postedLine(int $number, string $end): DeferralScheduleLine
    {
        $line = new DeferralScheduleLine([
            'line_no' => $number,
            'period_end' => $end,
            'status' => DeferralLineStatus::Posted,
        ]);

        return $line;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function sum(array $lines, string $key): string
    {
        $total = '0';
        foreach ($lines as $line) {
            $total = JournalMath::add($total, $line[$key]);
        }

        return JournalMath::store($total);
    }
}
