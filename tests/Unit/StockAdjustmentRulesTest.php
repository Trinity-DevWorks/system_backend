<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\StockAdjustmentReasonDirection;
use App\Modules\Inventory\Stock\Enums\StockAdjustmentStatus;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Inventory\Stock\Support\StockAdjustmentRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockAdjustmentRulesTest extends TestCase
{
    public function test_status_values_are_draft_and_posted(): void
    {
        $this->assertSame(['draft', 'posted'], StockAdjustmentStatus::values());
    }

    public function test_assert_draft_rejects_posted_document(): void
    {
        $document = new StockAdjustment(['status' => StockAdjustmentStatus::Posted]);

        $this->expectException(HttpException::class);
        StockAdjustmentRules::assertDraft($document);
    }

    public function test_increase_direction_allows_positive_only(): void
    {
        $this->assertTrue(StockAdjustmentReasonDirection::Increase->allows(1.5));
        $this->assertFalse(StockAdjustmentReasonDirection::Increase->allows(-1));
        $this->assertFalse(StockAdjustmentReasonDirection::Increase->allows(0));
    }

    public function test_decrease_direction_allows_negative_only(): void
    {
        $this->assertTrue(StockAdjustmentReasonDirection::Decrease->allows(-2));
        $this->assertFalse(StockAdjustmentReasonDirection::Decrease->allows(2));
        $this->assertFalse(StockAdjustmentReasonDirection::Decrease->allows(0));
    }

    public function test_both_direction_allows_any_nonzero(): void
    {
        $this->assertTrue(StockAdjustmentReasonDirection::Both->allows(1));
        $this->assertTrue(StockAdjustmentReasonDirection::Both->allows(-1));
        $this->assertFalse(StockAdjustmentReasonDirection::Both->allows(0));
    }

    public function test_quantity_must_match_reason_direction(): void
    {
        $reason = new StockAdjustmentReason([
            'direction' => StockAdjustmentReasonDirection::Decrease,
            'is_active' => true,
        ]);

        $this->expectException(HttpException::class);
        StockAdjustmentRules::assertQuantityMatchesReason($reason, 4);
    }
}
