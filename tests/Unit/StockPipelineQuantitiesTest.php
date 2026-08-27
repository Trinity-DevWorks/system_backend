<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Support\StockPipelineQuantities;
use Tests\TestCase;

class StockPipelineQuantitiesTest extends TestCase
{
    public function test_projected_adds_on_hand_open_po_and_inbound_transfer(): void
    {
        $pipeline = StockPipelineQuantities::compose('2', '3', '1.5', '9');

        $this->assertSame('3.000000', $pipeline['on_order_qty']);
        $this->assertSame('1.500000', $pipeline['in_transit_in_qty']);
        $this->assertSame('9.000000', $pipeline['in_transit_out_qty']);
        $this->assertSame('6.500000', $pipeline['projected_qty']);
    }

    public function test_in_transit_out_does_not_reduce_projected(): void
    {
        $pipeline = StockPipelineQuantities::compose('10', '0', '0', '4');

        $this->assertSame('10.000000', $pipeline['projected_qty']);
    }
}
