<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Modules\Inventory\Stock\Support\ReplenishmentAlertRules;
use Tests\TestCase;

class ReplenishmentAlertRulesTest extends TestCase
{
    public function test_incoming_stock_clears_out_of_stock_alert(): void
    {
        $this->assertSame(
            ReplenishmentAlertStatus::OutOfStock,
            ReplenishmentAlertRules::status(0, 10, 2),
        );
        $this->assertSame(
            ReplenishmentAlertStatus::Ok,
            ReplenishmentAlertRules::status(12, 10, 2),
        );
    }

    public function test_suggested_qty_uses_available_not_on_hand_only(): void
    {
        $this->assertSame('10.000000', ReplenishmentAlertRules::suggestedOrderQty(0, 10, null, null));
        $this->assertSame('0.000000', ReplenishmentAlertRules::suggestedOrderQty(10, 10, null, null));
        $this->assertSame('0.000000', ReplenishmentAlertRules::suggestedOrderQty(5, 20, null, 5));
    }
}
