<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Support;

use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;

final class GoodsReceiptRules
{
    public static function assertDraft(GoodsReceipt $receipt): void
    {
        if ($receipt->status !== GoodsReceiptStatus::Draft) {
            abort(422, 'Only draft goods receipts can be modified.', ['X-Error-Code' => 'GOODS_RECEIPT_NOT_DRAFT']);
        }
    }

    public static function assertPostable(GoodsReceipt $receipt): void
    {
        self::assertDraft($receipt);

        if ($receipt->lines()->count() === 0) {
            abort(422, 'Cannot post a goods receipt without lines.', ['X-Error-Code' => 'GOODS_RECEIPT_NO_LINES']);
        }
    }

    public static function assertReceivablePurchaseOrder(PurchaseOrder $order): void
    {
        if (! in_array($order->status, [PurchaseOrderStatus::Confirmed, PurchaseOrderStatus::Sent], true)) {
            abort(422, 'Only confirmed or sent purchase orders can be received.', [
                'X-Error-Code' => 'GOODS_RECEIPT_PO_NOT_RECEIVABLE',
            ]);
        }
    }

    public static function openQuantity(PurchaseOrderLine $line): string
    {
        $open = bcsub((string) $line->quantity, (string) $line->received_quantity, 6);

        return bccomp($open, '0', 6) < 0 ? '0.000000' : $open;
    }

    public static function isFullyReceived(PurchaseOrder $order): bool
    {
        $lines = $order->relationLoaded('lines')
            ? $order->lines
            : $order->lines()->get();

        if ($lines->isEmpty()) {
            return false;
        }

        foreach ($lines as $line) {
            if (bccomp(self::openQuantity($line), '0', 6) > 0) {
                return false;
            }
        }

        return true;
    }
}
