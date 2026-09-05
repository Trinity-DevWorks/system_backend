<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Purchasing\Support\PurchaseOrderMaxQuantity;
use Tests\TestCase;

class PurchaseOrderMaxQuantityTest extends TestCase
{
    public function test_missing_or_zero_max_is_not_a_ceiling(): void
    {
        $this->assertFalse(PurchaseOrderMaxQuantity::hasCeiling(null));
        $this->assertFalse(PurchaseOrderMaxQuantity::hasCeiling('0'));
        $this->assertFalse(PurchaseOrderMaxQuantity::hasCeiling('0.000000'));
        $this->assertTrue(PurchaseOrderMaxQuantity::hasCeiling('10'));
    }

    public function test_projected_adds_committed_and_this_document(): void
    {
        $this->assertSame('12.500000', PurchaseOrderMaxQuantity::projected('10', '2.5'));
    }

    public function test_equality_with_max_is_allowed(): void
    {
        $this->assertFalse(PurchaseOrderMaxQuantity::exceedsCeiling('10.000000', '10'));
        $this->assertTrue(PurchaseOrderMaxQuantity::exceedsCeiling('10.000001', '10'));
    }

    public function test_no_ceiling_never_exceeds(): void
    {
        $this->assertFalse(PurchaseOrderMaxQuantity::exceedsCeiling('999', null));
        $this->assertFalse(PurchaseOrderMaxQuantity::exceedsCeiling('999', '0'));
    }
}
