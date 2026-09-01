<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\CompanySetting\Enums\PriceRoundingMode;
use App\Modules\CompanySetting\Support\PriceMath;
use Tests\TestCase;

class PriceMathTest extends TestCase
{
    public function test_half_up_rounds_midpoint_away_from_zero(): void
    {
        $this->assertSame(2.0, PriceMath::round(1.5, 0, PriceRoundingMode::HalfUp));
        $this->assertSame(-2.0, PriceMath::round(-1.5, 0, PriceRoundingMode::HalfUp));
    }

    public function test_half_even_bankers_rounding(): void
    {
        $this->assertSame(2.0, PriceMath::round(1.5, 0, PriceRoundingMode::HalfEven));
        $this->assertSame(2.0, PriceMath::round(2.5, 0, PriceRoundingMode::HalfEven));
    }

    public function test_up_always_away_from_zero(): void
    {
        $this->assertSame(2.0, PriceMath::round(1.1, 0, PriceRoundingMode::Up));
        $this->assertSame(-2.0, PriceMath::round(-1.1, 0, PriceRoundingMode::Up));
    }

    public function test_down_toward_zero(): void
    {
        $this->assertSame(1.0, PriceMath::round(1.9, 0, PriceRoundingMode::Down));
        $this->assertSame(-1.0, PriceMath::round(-1.9, 0, PriceRoundingMode::Down));
    }
}
