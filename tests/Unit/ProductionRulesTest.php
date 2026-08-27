<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\ProductionStatus;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Inventory\Stock\Support\ProductionRules;
use App\Modules\Inventory\Stock\Support\ProductionScale;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductionRulesTest extends TestCase
{
    public function test_status_values_are_draft_and_posted(): void
    {
        $this->assertSame(['draft', 'posted'], ProductionStatus::values());
    }

    public function test_assert_draft_rejects_posted_document(): void
    {
        $document = new Production(['status' => ProductionStatus::Posted]);

        $this->expectException(HttpException::class);
        ProductionRules::assertDraft($document);
    }

    public function test_scale_multiplies_ingredient_qty_by_produce_over_yield(): void
    {
        $factor = ProductionScale::factor('6.000000', '2.000000');
        $this->assertSame('3.000000', ProductionScale::apply('1.000000', $factor));
    }

    public function test_zero_produce_qty_is_rejected(): void
    {
        $this->expectException(HttpException::class);
        ProductionScale::factor('0', '1');
    }
}
