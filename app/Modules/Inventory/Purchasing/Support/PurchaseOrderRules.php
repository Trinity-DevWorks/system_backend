<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Support;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Models\Warehouse;

final class PurchaseOrderRules
{
    public static function assertDraft(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrderStatus::Draft) {
            abort(422, 'Only draft purchase orders can be modified.', ['X-Error-Code' => 'PURCHASE_ORDER_NOT_DRAFT']);
        }
    }

    public static function assertCancellable(PurchaseOrder $order): void
    {
        if ($order->status === PurchaseOrderStatus::Cancelled) {
            abort(422, 'Purchase order is already cancelled.', ['X-Error-Code' => 'PURCHASE_ORDER_ALREADY_CANCELLED']);
        }

        if ($order->status === PurchaseOrderStatus::Confirmed) {
            $hasReceipts = $order->lines()
                ->where(function ($query): void {
                    $query->where('received_quantity', '>', 0)
                        ->orWhere('received_base_quantity', '>', 0);
                })
                ->exists();

            if ($hasReceipts) {
                abort(422, 'Cannot cancel a purchase order with received quantities.', ['X-Error-Code' => 'PURCHASE_ORDER_HAS_RECEIPTS']);
            }
        }
    }

    public static function assertSupplier(string $supplierId): void
    {
        $supplier = Supplier::query()->findOrFail($supplierId);

        if (! $supplier->is_active) {
            abort(422, 'Supplier must be active.', ['X-Error-Code' => 'PURCHASE_ORDER_SUPPLIER_INACTIVE']);
        }
    }

    public static function assertWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            abort(422, 'Warehouse must be active.', ['X-Error-Code' => 'PURCHASE_ORDER_WAREHOUSE_INACTIVE']);
        }
    }

    public static function assertPurchasableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Item must be active.', ['X-Error-Code' => 'PURCHASE_ORDER_ITEM_INACTIVE']);
        }

        if (! $item->allow_purchase) {
            abort(422, 'Item is not purchasable.', ['X-Error-Code' => 'PURCHASE_ORDER_ITEM_NOT_PURCHASABLE']);
        }
    }

    public static function assertPrintable(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrderStatus::Confirmed) {
            abort(422, 'Only confirmed purchase orders can be printed.', ['X-Error-Code' => 'PURCHASE_ORDER_PDF_NOT_ALLOWED']);
        }
    }

    public static function assertMarkAsSent(PurchaseOrder $order): void
    {
        if ($order->status !== PurchaseOrderStatus::Confirmed) {
            abort(422, 'Only confirmed purchase orders can be marked as sent.', ['X-Error-Code' => 'PURCHASE_ORDER_MARK_SENT_NOT_ALLOWED']);
        }
    }
}
