<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Support\StockTransferRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockTransferRulesTest extends TestCase
{
    public function test_status_values_include_two_step_lifecycle(): void
    {
        $this->assertSame(
            ['draft', 'in_transit', 'received', 'cancelled'],
            StockTransferStatus::values(),
        );
    }

    public function test_assert_dispatchable_rejects_in_transit_transfer(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::InTransit]);

        $this->expectException(HttpException::class);
        StockTransferRules::assertDispatchable($transfer);
    }

    public function test_assert_receivable_rejects_draft_transfer(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::Draft]);

        $this->expectException(HttpException::class);
        StockTransferRules::assertReceivable($transfer);
    }

    public function test_assert_cancellable_allows_draft_and_in_transit(): void
    {
        StockTransferRules::assertCancellable(new StockTransfer(['status' => StockTransferStatus::Draft]));
        StockTransferRules::assertCancellable(new StockTransfer(['status' => StockTransferStatus::InTransit]));

        $this->assertTrue(true);
    }

    public function test_assert_cancellable_rejects_received_transfer(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::Received]);

        $this->expectException(HttpException::class);
        StockTransferRules::assertCancellable($transfer);
    }
}
