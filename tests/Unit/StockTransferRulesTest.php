<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\StockTransferClosureOutcome;
use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Models\StockTransferLine;
use App\Modules\Inventory\Stock\Support\StockTransferLineQuantity;
use App\Modules\Inventory\Stock\Support\StockTransferRules;
use App\Modules\Warehouse\Models\Warehouse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockTransferRulesTest extends TestCase
{
    public function test_status_values_include_partial_receive(): void
    {
        $this->assertSame(
            ['draft', 'in_transit', 'partially_received', 'received', 'cancelled'],
            StockTransferStatus::values(),
        );
    }

    public function test_closure_outcomes_are_return_and_write_off(): void
    {
        $this->assertSame(['return', 'write_off'], StockTransferClosureOutcome::values());
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

    public function test_assert_receivable_allows_partially_received_with_open_qty(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::PartiallyReceived]);
        $transfer->setRelation('lines', collect([
            new StockTransferLine([
                'quantity' => '10',
                'base_quantity' => '10',
                'received_quantity' => '4',
                'received_base_quantity' => '4',
                'returned_quantity' => '0',
                'returned_base_quantity' => '0',
                'written_off_quantity' => '0',
                'written_off_base_quantity' => '0',
            ]),
        ]));

        StockTransferRules::assertReceivable($transfer);

        $this->assertTrue(true);
    }

    public function test_assert_cancellable_allows_unreceived_in_transit(): void
    {
        $inTransit = new StockTransfer(['status' => StockTransferStatus::InTransit]);
        $inTransit->setRelation('lines', collect([
            new StockTransferLine([
                'quantity' => '5',
                'base_quantity' => '5',
                'received_quantity' => '0',
                'received_base_quantity' => '0',
                'returned_quantity' => '0',
                'returned_base_quantity' => '0',
                'written_off_quantity' => '0',
                'written_off_base_quantity' => '0',
            ]),
        ]));
        StockTransferRules::assertCancellable($inTransit);

        $this->assertTrue(true);
    }

    public function test_assert_cancellable_rejects_draft_transfer(): void
    {
        $this->expectException(HttpException::class);
        StockTransferRules::assertCancellable(new StockTransfer(['status' => StockTransferStatus::Draft]));
    }

    public function test_assert_cancellable_rejects_received_transfer(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::Received]);

        $this->expectException(HttpException::class);
        StockTransferRules::assertCancellable($transfer);
    }

    public function test_assert_cancellable_rejects_partially_received_transfer(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::PartiallyReceived]);
        $transfer->setRelation('lines', collect([
            new StockTransferLine([
                'quantity' => '10',
                'received_quantity' => '4',
                'returned_quantity' => '0',
                'written_off_quantity' => '0',
                'received_base_quantity' => '4',
                'returned_base_quantity' => '0',
                'written_off_base_quantity' => '0',
            ]),
        ]));

        $this->expectException(HttpException::class);
        StockTransferRules::assertCancellable($transfer);
    }

    public function test_open_quantity_subtracts_received_returned_and_written_off(): void
    {
        $line = new StockTransferLine([
            'quantity' => '10',
            'base_quantity' => '20',
            'received_quantity' => '3',
            'received_base_quantity' => '6',
            'returned_quantity' => '2',
            'returned_base_quantity' => '4',
            'written_off_quantity' => '1',
            'written_off_base_quantity' => '2',
        ]);

        $this->assertSame('4.000000', StockTransferLineQuantity::openQuantity($line));
        $this->assertSame('8.000000', StockTransferLineQuantity::openBaseQuantity($line));
    }

    public function test_open_quantity_never_goes_negative(): void
    {
        $line = new StockTransferLine([
            'quantity' => '2',
            'base_quantity' => '2',
            'received_quantity' => '5',
            'received_base_quantity' => '5',
            'returned_quantity' => '0',
            'returned_base_quantity' => '0',
            'written_off_quantity' => '0',
            'written_off_base_quantity' => '0',
        ]);

        $this->assertSame('0.000000', StockTransferLineQuantity::openQuantity($line));
        $this->assertSame('0.000000', StockTransferLineQuantity::openBaseQuantity($line));
    }

    public function test_assert_closeable_rejects_draft(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::Draft]);

        $this->expectException(HttpException::class);
        StockTransferRules::assertCloseable($transfer);
    }

    public function test_assert_closeable_rejects_in_transit(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::InTransit]);
        $transfer->setRelation('lines', collect([
            new StockTransferLine([
                'quantity' => '5',
                'base_quantity' => '5',
                'received_quantity' => '0',
                'received_base_quantity' => '0',
                'returned_quantity' => '0',
                'returned_base_quantity' => '0',
                'written_off_quantity' => '0',
                'written_off_base_quantity' => '0',
            ]),
        ]));

        $this->expectException(HttpException::class);
        StockTransferRules::assertCloseable($transfer);
    }

    public function test_assert_closeable_allows_partially_received_with_open_qty(): void
    {
        $transfer = new StockTransfer(['status' => StockTransferStatus::PartiallyReceived]);
        $transfer->setRelation('lines', collect([
            new StockTransferLine([
                'quantity' => '10',
                'base_quantity' => '10',
                'received_quantity' => '4',
                'received_base_quantity' => '4',
                'returned_quantity' => '0',
                'returned_base_quantity' => '0',
                'written_off_quantity' => '0',
                'written_off_base_quantity' => '0',
            ]),
        ]));

        StockTransferRules::assertCloseable($transfer);

        $this->assertTrue(true);
    }

    public function test_destination_manager_matches_warehouse_manager(): void
    {
        $transfer = $this->transferForDestinationManager('user-manager');

        $this->assertTrue(StockTransferRules::isDestinationWarehouseManager($transfer, 'user-manager'));
        $this->assertFalse(StockTransferRules::isDestinationWarehouseManager($transfer, 'user-other'));
        $this->assertFalse(StockTransferRules::isDestinationWarehouseManager($transfer, null));
    }

    public function test_destination_manager_rejects_warehouse_without_manager(): void
    {
        $transfer = $this->transferForDestinationManager(null);

        $this->assertFalse(StockTransferRules::isDestinationWarehouseManager($transfer, 'user-manager'));
    }

    public function test_cancel_actor_allows_dispatcher(): void
    {
        $transfer = $this->transferForCancelActor('user-dispatcher', 'user-source-manager');

        $this->assertTrue(StockTransferRules::canCancelTransitActor($transfer, 'user-dispatcher'));
        $this->assertFalse(StockTransferRules::canCancelTransitActor($transfer, 'user-other'));
    }

    public function test_cancel_actor_allows_source_warehouse_manager(): void
    {
        $transfer = $this->transferForCancelActor('user-dispatcher', 'user-source-manager');

        $this->assertTrue(StockTransferRules::canCancelTransitActor($transfer, 'user-source-manager'));
    }

    public function test_cancel_actor_rejects_when_neither_dispatcher_nor_source_manager(): void
    {
        $transfer = $this->transferForCancelActor(null, null);

        $this->assertFalse(StockTransferRules::canCancelTransitActor($transfer, 'user-other'));
    }

    private function transferForDestinationManager(?string $managerId): StockTransfer
    {
        $warehouse = new Warehouse(['manager_id' => $managerId]);
        $warehouse->id = 2;
        $transfer = new StockTransfer(['to_warehouse_id' => 2]);
        $transfer->setRelation('toWarehouse', $warehouse);

        return $transfer;
    }

    private function transferForCancelActor(?string $dispatchedBy, ?string $sourceManagerId): StockTransfer
    {
        $warehouse = new Warehouse(['manager_id' => $sourceManagerId]);
        $warehouse->id = 1;
        $transfer = new StockTransfer([
            'from_warehouse_id' => 1,
            'dispatched_by' => $dispatchedBy,
        ]);
        $transfer->setRelation('fromWarehouse', $warehouse);

        return $transfer;
    }
}
