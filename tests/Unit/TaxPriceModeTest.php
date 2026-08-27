<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\TenantSetting\Enums\TaxPriceMode;
use Tests\TestCase;

class TaxPriceModeTest extends TestCase
{
    public function test_values_are_exclusive_and_inclusive(): void
    {
        $this->assertSame(['exclusive', 'inclusive'], TaxPriceMode::values());
    }

    public function test_inclusive_only_applies_when_tax_is_enabled(): void
    {
        $this->assertTrue(TaxPriceMode::Inclusive->pricesIncludeTax(true));
        $this->assertFalse(TaxPriceMode::Inclusive->pricesIncludeTax(false));
        $this->assertFalse(TaxPriceMode::Exclusive->pricesIncludeTax(true));
        $this->assertFalse(TaxPriceMode::Exclusive->pricesIncludeTax(false));
    }
}
