<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\PriceChange\Support\PriceChangeMath;
use PHPUnit\Framework\TestCase;

class PriceChangeMathTest extends TestCase
{
    public function test_percent_amount_and_margin(): void
    {
        $this->assertSame('10.50000000', PriceChangeMath::compute('10', null, 'percent', '5'));
        $this->assertSame('10.12000000', PriceChangeMath::compute('10', null, 'amount', '0.12'));
        $this->assertSame('6.00000000', PriceChangeMath::compute(null, '4', 'margin', '50'));
        $this->assertNull(PriceChangeMath::compute(null, null, 'percent', '5'));
        $this->assertSame('0', PriceChangeMath::compute('1', null, 'amount', '-5'));
    }

    public function test_rounding_steps(): void
    {
        $this->assertSame('10.100000', PriceChangeMath::round('10.12', '0.05'));
        $this->assertSame('10.000000', PriceChangeMath::round('10.10', '0.25'));
        $this->assertSame('9.990000', PriceChangeMath::round('10.40', '0.99'));
        $this->assertSame('10.990000', PriceChangeMath::round('10.60', '0.99'));
        $this->assertSame('10.40', PriceChangeMath::round('10.40', 'none'));
    }

    public function test_change_percent_is_null_when_the_old_price_is_missing_or_zero(): void
    {
        $this->assertNull(PriceChangeMath::changePercent(null, '2'));
        $this->assertNull(PriceChangeMath::changePercent('0', '2'));
        $this->assertSame('16.6667', PriceChangeMath::changePercent('1.50', '1.75'));
    }
}
