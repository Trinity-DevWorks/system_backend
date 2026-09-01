<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\PurchaseInvoice\Support\PurchaseInvoiceRules;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PurchaseInvoiceRulesTest extends TestCase
{
    public function test_open_quantity_is_received_minus_invoiced_in_line_uom(): void
    {
        $line = new GoodsReceiptLine([
            'quantity' => '10',
            'base_quantity' => '10',
        ]);
        $line->id = 1;

        $this->assertSame('10.000000', PurchaseInvoiceRules::openQuantityUsingMap($line, []));
        $this->assertSame('6.500000', PurchaseInvoiceRules::openQuantityUsingMap($line, [1 => '3.500000']));
    }

    public function test_open_quantity_never_goes_negative(): void
    {
        $line = new GoodsReceiptLine([
            'quantity' => '2',
            'base_quantity' => '2',
        ]);
        $line->id = 7;

        $this->assertSame('0.000000', PurchaseInvoiceRules::openQuantityUsingMap($line, [7 => '5']));
    }

    public function test_open_quantity_scales_when_line_uom_differs_from_base(): void
    {
        $line = new GoodsReceiptLine([
            'quantity' => '2',
            'base_quantity' => '20',
        ]);
        $line->id = 3;

        $this->assertSame('1.000000', PurchaseInvoiceRules::openQuantityUsingMap($line, [3 => '10']));
    }

    public function test_assert_invoiceable_rejects_draft_goods_receipt(): void
    {
        $receipt = new GoodsReceipt(['status' => GoodsReceiptStatus::Draft]);

        $this->expectException(HttpException::class);
        PurchaseInvoiceRules::assertInvoiceableGoodsReceipt($receipt);
    }

    public function test_assert_invoiceable_rejects_posted_receipt_without_supplier(): void
    {
        $receipt = new GoodsReceipt([
            'status' => GoodsReceiptStatus::Posted,
            'supplier_id' => null,
        ]);

        $this->expectException(HttpException::class);
        PurchaseInvoiceRules::assertInvoiceableGoodsReceipt($receipt);
    }

    public function test_has_open_to_invoice_requires_posted_status(): void
    {
        $receipt = new GoodsReceipt(['status' => GoodsReceiptStatus::Draft]);
        $line = new GoodsReceiptLine(['quantity' => '4', 'base_quantity' => '4']);
        $line->id = 11;
        $receipt->setRelation('lines', collect([$line]));

        $this->assertFalse(PurchaseInvoiceRules::hasOpenToInvoice($receipt, []));
    }

    public function test_has_open_to_invoice_is_true_when_remaining_qty_exists(): void
    {
        $receipt = new GoodsReceipt(['status' => GoodsReceiptStatus::Posted]);
        $line = new GoodsReceiptLine(['quantity' => '4', 'base_quantity' => '4']);
        $line->id = 12;
        $receipt->setRelation('lines', collect([$line]));

        $this->assertTrue(PurchaseInvoiceRules::hasOpenToInvoice($receipt, []));
        $this->assertFalse(PurchaseInvoiceRules::hasOpenToInvoice($receipt, [12 => '4']));
    }
}
