<?php

declare(strict_types=1);

namespace Tests\Unit\FixedAsset;

use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use App\Modules\Accounting\FixedAsset\Services\DepreciationCalculator;
use App\Modules\Accounting\FixedAsset\Services\DepreciationProfile;
use PHPUnit\Framework\TestCase;

class DepreciationCalculatorTest extends TestCase
{
    private DepreciationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DepreciationCalculator;
    }

    public function test_straight_line_exact_life_ends_on_salvage(): void
    {
        $asset = $this->profile(cost: '12000', salvage: '0', life: 12, inService: '2026-01-15');
        $schedule = $this->calculator->schedule($asset);

        $this->assertCount(12, $schedule);
        $this->assertSame('2026-02-01', $schedule[0]['month']);
        $this->assertSame('1000.00', $schedule[0]['amount']);
        $this->assertSame('1000.00', $schedule[11]['amount']);
        $this->assertSame('0.00', $schedule[11]['book_value_after']);
        $this->assertSame('12000.00', $this->sum($schedule));
    }

    public function test_straight_line_last_month_takes_the_remainder(): void
    {
        $asset = $this->profile(cost: '1000', salvage: '0', life: 3, inService: '2026-01-01', prorate: true);
        $schedule = $this->calculator->schedule($asset);

        $this->assertSame(['333.33', '333.33', '333.34'], array_column($schedule, 'amount'));
        $this->assertSame('0.00', $schedule[2]['book_value_after']);
        $this->assertSame('1000.00', $this->sum($schedule));
    }

    public function test_straight_line_keeps_salvage(): void
    {
        $asset = $this->profile(cost: '1000', salvage: '100', life: 3, inService: '2026-01-01', prorate: true);
        $schedule = $this->calculator->schedule($asset);

        $this->assertSame('100.00', $schedule[array_key_last($schedule)]['book_value_after']);
        $this->assertSame('900.00', $this->sum($schedule));
    }

    public function test_depreciation_starts_the_month_after_in_service_unless_prorated(): void
    {
        $later = $this->profile(cost: '1200', salvage: '0', life: 12, inService: '2026-03-15');
        $this->assertSame('0.00', $this->calculator->monthly($later, '2026-03-01')['amount']);
        $this->assertSame('100.00', $this->calculator->monthly($later, '2026-04-01')['amount']);
        $this->assertSame('2026-04-01', $this->calculator->schedule($later)[0]['month']);

        $prorated = $this->profile(cost: '1200', salvage: '0', life: 12, inService: '2026-03-15', prorate: true);
        $this->assertSame('100.00', $this->calculator->monthly($prorated, '2026-03-01')['amount']);
        $this->assertSame('2026-03-01', $this->calculator->schedule($prorated)[0]['month']);
    }

    public function test_declining_balance_starts_faster_then_hits_salvage(): void
    {
        $asset = $this->profile(
            cost: '1200',
            salvage: '0',
            life: 12,
            inService: '2026-01-01',
            prorate: true,
            method: DepreciationMethod::DecliningBalance,
        );
        $schedule = $this->calculator->schedule($asset);

        $this->assertSame('200.00', $schedule[0]['amount']);
        $this->assertSame('1000.00', $schedule[0]['book_value_after']);
        $this->assertSame('166.67', $schedule[1]['amount']);
        $this->assertSame('0.00', $schedule[array_key_last($schedule)]['book_value_after']);
        $this->assertSame('1200.00', $this->sum($schedule));
    }

    public function test_declining_balance_switches_to_straight_line_and_stops_at_salvage(): void
    {
        $switches = $this->profile(
            cost: '1000',
            salvage: '0',
            life: 12,
            inService: '2026-01-01',
            prorate: true,
            method: DepreciationMethod::DecliningBalance,
            rate: '50',
        );
        $this->assertSame('83.33', $this->calculator->monthly($switches, '2026-01-01')['amount']);

        $floor = $this->profile(
            cost: '1000',
            salvage: '800',
            life: 12,
            inService: '2026-01-01',
            prorate: true,
            method: DepreciationMethod::DecliningBalance,
        );
        $schedule = $this->calculator->schedule($floor);

        $this->assertSame(['166.67', '33.33'], array_column($schedule, 'amount'));
        $this->assertSame('800.00', $schedule[1]['book_value_after']);
        $this->assertSame('200.00', $this->sum($schedule));
    }

    public function test_catch_up_sums_the_missing_months(): void
    {
        $asset = $this->profile(cost: '12000', salvage: '0', life: 12, inService: '2026-01-15');
        $this->assertSame('0.00', $this->calculator->catchUp($asset, '2026-01-01'));
        $this->assertSame('3000.00', $this->calculator->catchUp($asset, '2026-04-01'));
    }

    public function test_a_changed_estimate_spreads_the_remaining_book_value(): void
    {
        $original = $this->profile(cost: '1000', salvage: '0', life: 3, inService: '2026-01-01', prorate: true);
        $first = $this->calculator->monthly($original, '2026-01-01');
        $this->assertSame('333.33', $first['amount']);

        $revised = $this->profile(
            cost: '1000',
            salvage: '0',
            life: 10,
            inService: '2026-01-01',
            prorate: true,
            accumulated: '333.33',
            through: '2026-01-31',
        );
        $schedule = $this->calculator->schedule($revised);

        $this->assertSame('74.07', $schedule[0]['amount']);
        $this->assertSame('0.00', $schedule[array_key_last($schedule)]['book_value_after']);
        $this->assertSame('666.67', $this->sum($schedule));
    }

    private function profile(
        string $cost,
        string $salvage,
        int $life,
        string $inService,
        bool $prorate = false,
        DepreciationMethod $method = DepreciationMethod::StraightLine,
        ?string $rate = null,
        string $accumulated = '0',
        ?string $through = null,
    ): DepreciationProfile {
        return new DepreciationProfile(
            cost: $cost,
            salvageValue: $salvage,
            accumulatedDepreciation: $accumulated,
            method: $method,
            usefulLifeMonths: $life,
            decliningRate: $rate,
            prorateFirstMonth: $prorate,
            inServiceDate: $inService,
            depreciatedThrough: $through,
        );
    }

    /**
     * @param  list<array{amount: string}>  $schedule
     */
    private function sum(array $schedule): string
    {
        $total = '0.00';
        foreach ($schedule as $row) {
            $total = bcadd($total, $row['amount'], 2);
        }

        return $total;
    }
}
