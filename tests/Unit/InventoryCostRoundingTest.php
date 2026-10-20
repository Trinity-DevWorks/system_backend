<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Support\InventoryCostingMath;
use PHPUnit\Framework\TestCase;

class InventoryCostRoundingTest extends TestCase
{
    public function test_box_total_stays_the_entered_amount(): void
    {
        $this->assertSame('60.0000', InventoryCostingMath::extended(6, '10'));
        $this->assertSame('5000.0000', InventoryCostingMath::extended(500, '10'));
    }

    public function test_piece_cost_keeps_four_decimals(): void
    {
        $this->assertSame('0.8333', InventoryCostingMath::moneyRound('0.8333'));
        $this->assertSame('0.8333', InventoryCostingMath::moneyRound(bcdiv('10.00', '12', 4)));
    }

    public function test_return_slice_puts_the_original_value_back(): void
    {
        $this->assertSame('60.0000', InventoryCostingMath::remainingSlice('60.0000', '72', '72'));
        $this->assertSame('30.0000', InventoryCostingMath::remainingSlice('60.0000', '36', '72'));

        $first = InventoryCostingMath::remainingSlice('10.0000', '1', '3');
        $second = InventoryCostingMath::remainingSlice(bcsub('10.0000', $first, 4), '1', '2');
        $last = InventoryCostingMath::remainingSlice(bcsub(bcsub('10.0000', $first, 4), $second, 4), '1', '1');

        $this->assertSame('10.0000', bcadd(bcadd($first, $second, 4), $last, 4));
    }
}
