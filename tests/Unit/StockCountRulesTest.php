<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\StockCountStatus;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Support\StockCountRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockCountRulesTest extends TestCase
{
    public function test_status_values_are_draft_and_posted(): void
    {
        $this->assertSame(['draft', 'posted'], StockCountStatus::values());
    }

    public function test_assert_draft_rejects_posted_document(): void
    {
        $document = new StockCount(['status' => StockCountStatus::Posted]);

        $this->expectException(HttpException::class);
        StockCountRules::assertDraft($document);
    }

    public function test_movement_type_includes_count(): void
    {
        $this->assertContains('count', StockMovementType::values());
    }
}
