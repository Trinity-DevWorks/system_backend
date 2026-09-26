<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class PurchaseInvoiceRules
{
    public static function assertDraft(PurchaseInvoice $invoice): void
    {
        if ($invoice->status !== PurchaseInvoiceStatus::Draft) {
            abort(422, 'Only draft purchase invoices can be modified.', ['X-Error-Code' => 'PURCHASE_INVOICE_NOT_DRAFT']);
        }
    }

    public static function assertPostable(PurchaseInvoice $invoice): void
    {
        self::assertDraft($invoice);

        if ($invoice->lines()->count() === 0) {
            abort(422, 'Cannot post a purchase invoice without lines.', ['X-Error-Code' => 'PURCHASE_INVOICE_NO_LINES']);
        }
    }

    public static function assertSupplier(string $supplierId): Supplier
    {
        $supplier = Supplier::query()->findOrFail($supplierId);

        if (! $supplier->is_active) {
            abort(422, 'Supplier must be active.', ['X-Error-Code' => 'PURCHASE_INVOICE_SUPPLIER_INACTIVE']);
        }

        return $supplier;
    }

    public static function assertWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            abort(422, 'Warehouse must be active.', ['X-Error-Code' => 'PURCHASE_INVOICE_WAREHOUSE_INACTIVE']);
        }

        app(WarehouseService::class)->assertVisible($warehouse);
    }

    public static function assertPurchasableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Item must be active.', ['X-Error-Code' => 'PURCHASE_INVOICE_ITEM_INACTIVE']);
        }

        if (! $item->allow_purchase) {
            abort(422, 'Item is not purchasable.', ['X-Error-Code' => 'PURCHASE_INVOICE_ITEM_NOT_PURCHASABLE']);
        }
    }

    public static function assertPostedGoodsReceipt(?string $goodsReceiptId, string $supplierId): ?GoodsReceipt
    {
        if ($goodsReceiptId === null || $goodsReceiptId === '') {
            return null;
        }

        $receipt = GoodsReceipt::query()->findOrFail($goodsReceiptId);

        if ($receipt->status !== GoodsReceiptStatus::Posted) {
            abort(422, 'Goods receipt must be posted.', ['X-Error-Code' => 'PURCHASE_INVOICE_GRN_NOT_POSTED']);
        }

        if ((string) $receipt->supplier_id !== $supplierId) {
            abort(422, 'Goods receipt supplier does not match this invoice.', ['X-Error-Code' => 'PURCHASE_INVOICE_GRN_SUPPLIER_MISMATCH']);
        }

        return $receipt;
    }

    /**
     * One goods receipt may belong to only one purchase invoice (draft or posted).
     */
    public static function assertGoodsReceiptAvailable(?GoodsReceipt $receipt, ?string $exceptInvoiceId = null): void
    {
        if ($receipt === null) {
            return;
        }

        $taken = PurchaseInvoice::query()
            ->where('goods_receipt_id', $receipt->id)
            ->when(
                $exceptInvoiceId !== null && $exceptInvoiceId !== '',
                fn ($query) => $query->where('id', '!=', $exceptInvoiceId),
            )
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            abort(422, 'This goods receipt is already linked to a purchase invoice.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_GRN_ALREADY_LINKED',
            ]);
        }
    }

    public static function assertExclusiveSource(?string $goodsReceiptId, ?string $purchaseOrderId): void
    {
        $hasReceipt = $goodsReceiptId !== null && $goodsReceiptId !== '';
        $hasOrder = $purchaseOrderId !== null && $purchaseOrderId !== '';

        if ($hasReceipt && $hasOrder) {
            abort(422, 'Link either a goods receipt or a purchase order, not both.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_SOURCE_EXCLUSIVE',
            ]);
        }
    }

    /**
     * Direct purchase-order billing: confirmed/sent, same supplier, no goods receipt, not already invoiced.
     */
    public static function assertDirectPurchaseOrder(?string $purchaseOrderId, string $supplierId, ?string $exceptInvoiceId = null): ?PurchaseOrder
    {
        if ($purchaseOrderId === null || $purchaseOrderId === '') {
            return null;
        }

        $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($purchaseOrderId);

        if (! in_array($order->status, [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Sent], true)) {
            abort(422, 'Only a confirmed or sent purchase order can be invoiced directly.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_PO_NOT_INVOICEABLE',
            ]);
        }

        if ((string) $order->supplier_id !== $supplierId) {
            abort(422, 'Purchase order supplier does not match this invoice.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_PO_SUPPLIER_MISMATCH',
            ]);
        }

        $hasReceipt = GoodsReceipt::query()->where('purchase_order_id', $order->id)->exists();
        if ($hasReceipt) {
            abort(422, 'This purchase order already has a goods receipt. Invoice the receipt instead.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_PO_HAS_GRN',
            ]);
        }

        $taken = PurchaseInvoice::query()
            ->where('purchase_order_id', $order->id)
            ->when(
                $exceptInvoiceId !== null && $exceptInvoiceId !== '',
                fn ($query) => $query->where('id', '!=', $exceptInvoiceId),
            )
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            abort(422, 'This purchase order is already linked to a purchase invoice.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_PO_ALREADY_LINKED',
            ]);
        }

        return $order;
    }

    public static function assertPurchaseOrderNotInvoiced(PurchaseOrder $order): void
    {
        $taken = PurchaseInvoice::query()
            ->where('purchase_order_id', $order->id)
            ->lockForUpdate()
            ->exists();

        if ($taken) {
            abort(422, 'This purchase order is already linked to a purchase invoice and cannot be received.', [
                'X-Error-Code' => 'GOODS_RECEIPT_PO_ALREADY_INVOICED',
            ]);
        }
    }
}
