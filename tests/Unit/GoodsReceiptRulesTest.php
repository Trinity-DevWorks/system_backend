<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\Inventory\Purchasing\Support\GoodsReceiptRules;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GoodsReceiptRulesTest extends TestCase
{
    public function test_status_values_are_draft_and_posted(): void
    {
        $this->assertSame(['draft', 'posted'], GoodsReceiptStatus::values());
    }

    public function test_open_quantity_is_ordered_minus_received(): void
    {
        $line = new PurchaseOrderLine([
            'quantity' => '10',
            'received_quantity' => '3.5',
        ]);

        $this->assertSame('6.500000', GoodsReceiptRules::openQuantity($line));
    }

    public function test_open_quantity_never_goes_negative(): void
    {
        $line = new PurchaseOrderLine([
            'quantity' => '2',
            'received_quantity' => '5',
        ]);

        $this->assertSame('0.000000', GoodsReceiptRules::openQuantity($line));
    }

    public function test_assert_receivable_rejects_draft_purchase_order(): void
    {
        $order = new PurchaseOrder(['status' => PurchaseOrderStatus::Draft]);

        $this->expectException(HttpException::class);
        GoodsReceiptRules::assertReceivablePurchaseOrder($order);
    }

    public function test_assert_draft_rejects_posted_receipt(): void
    {
        $receipt = new GoodsReceipt(['status' => GoodsReceiptStatus::Posted]);

        $this->expectException(HttpException::class);
        GoodsReceiptRules::assertDraft($receipt);
    }

    public function test_assert_receivable_rejects_closed_purchase_order(): void
    {
        $order = new PurchaseOrder(['status' => PurchaseOrderStatus::Closed]);

        $this->expectException(HttpException::class);
        GoodsReceiptRules::assertReceivablePurchaseOrder($order);
    }

    public function test_assert_cancellable_rejects_closed_purchase_order(): void
    {
        $order = new PurchaseOrder(['status' => PurchaseOrderStatus::Closed]);

        $this->expectException(HttpException::class);
        PurchaseOrderRules::assertCancellable($order);
    }

    public function test_is_fully_received_when_every_line_is_complete(): void
    {
        $order = new PurchaseOrder;
        $order->setRelation('lines', collect([
            new PurchaseOrderLine(['quantity' => '10', 'received_quantity' => '10']),
            new PurchaseOrderLine(['quantity' => '2', 'received_quantity' => '2']),
        ]));

        $this->assertTrue(GoodsReceiptRules::isFullyReceived($order));
    }

    public function test_is_fully_received_false_when_any_line_is_open(): void
    {
        $order = new PurchaseOrder;
        $order->setRelation('lines', collect([
            new PurchaseOrderLine(['quantity' => '10', 'received_quantity' => '10']),
            new PurchaseOrderLine(['quantity' => '2', 'received_quantity' => '1']),
        ]));

        $this->assertFalse(GoodsReceiptRules::isFullyReceived($order));
    }

    public function test_purchase_order_status_values_include_closed(): void
    {
        $this->assertSame(
            ['draft', 'confirmed', 'sent', 'closed', 'cancelled'],
            PurchaseOrderStatus::values()
        );
    }
}
