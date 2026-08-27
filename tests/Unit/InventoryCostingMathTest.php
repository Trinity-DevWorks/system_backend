<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\InventoryCostingMethod;
use App\Modules\Inventory\Stock\Support\InventoryCostingMath;
use Tests\TestCase;

class InventoryCostingMathTest extends TestCase
{
    public function test_method_values(): void
    {
        $this->assertSame(
            ['standard', 'fifo', 'moving_average', 'actual'],
            InventoryCostingMethod::values(),
        );
        $this->assertTrue(InventoryCostingMethod::Fifo->usesLayers());
        $this->assertTrue(InventoryCostingMethod::Actual->usesLayers());
        $this->assertFalse(InventoryCostingMethod::Standard->usesLayers());
        $this->assertFalse(InventoryCostingMethod::MovingAverage->usesLayers());
    }

    public function test_moving_average_blends_inbound_cost(): void
    {
        $avg = InventoryCostingMath::movingAverageAfterInbound(
            '10.000000',
            '100.0000',
            '10.000000',
            '30.0000',
        );

        $this->assertSame('20.0000', $avg);
    }

    public function test_unit_cost_from_value(): void
    {
        $this->assertSame('12.5000', InventoryCostingMath::unitCostFromValue('2.000000', '25.0000'));
        $this->assertSame('0.0000', InventoryCostingMath::unitCostFromValue('0.000000', '25.0000'));
    }

    public function test_inbound_after_negative_on_hand_values_only_the_remainder(): void
    {
        $recovered = InventoryCostingMath::inboundAfterNonPositiveOnHand(
            '-5.000000',
            '10.000000',
            '6.0000',
        );

        $this->assertSame('5.000000', $recovered['qty']);
        $this->assertSame('30.0000', $recovered['value']);
    }

    public function test_inbound_that_does_not_cover_the_hole_has_no_value(): void
    {
        $stillShort = InventoryCostingMath::inboundAfterNonPositiveOnHand(
            '-5.000000',
            '3.000000',
            '6.0000',
        );

        $this->assertSame('-2.000000', $stillShort['qty']);
        $this->assertSame('0.0000', $stillShort['value']);
    }
}
