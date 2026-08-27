<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\OpeningStockStatus;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Support\OpeningStockRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OpeningStockRulesTest extends TestCase
{
    public function test_status_values_are_draft_and_posted(): void
    {
        $this->assertSame(['draft', 'posted'], OpeningStockStatus::values());
    }

    public function test_assert_draft_rejects_posted_document(): void
    {
        $document = new OpeningStock(['status' => OpeningStockStatus::Posted]);

        $this->expectException(HttpException::class);
        OpeningStockRules::assertDraft($document);
    }
}
