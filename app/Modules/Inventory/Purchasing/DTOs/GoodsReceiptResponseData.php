<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\DTOs;

use App\Models\User;
use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\PurchaseInvoice\Support\PurchaseInvoiceRules;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Collection;

readonly class GoodsReceiptResponseData
{
    /**
     * @param  array<int, string>|null  $invoicedBaseByLineId
     */
    public static function fromModel(GoodsReceipt $receipt, bool $includeLines = true, ?array $invoicedBaseByLineId = null): array
    {
        $receipt->loadMissing([
            'purchaseOrder:id,po_number,supplier_id,status,warehouse_id',
            'purchaseOrder.supplier:id,supplier_code,name,is_active',
            'supplier:id,supplier_code,name,is_active',
            'warehouse:id,name,shortcut_name,is_active',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $order = $receipt->purchaseOrder;
        $supplier = $receipt->supplier ?? $order?->supplier;

        $payload = [
            'id' => $receipt->id,
            'grn_number' => $receipt->grn_number,
            'purchase_order_id' => $receipt->purchase_order_id,
            'has_purchase_order' => $receipt->purchase_order_id !== null,
            'supplier_id' => $receipt->supplier_id ?? $order?->supplier_id,
            'warehouse_id' => $receipt->warehouse_id,
            'status' => $receipt->status->value,
            'received_date' => $receipt->received_date?->toDateString(),
            'notes' => $receipt->notes,
            'purchase_order' => $order ? [
                'id' => $order->id,
                'po_number' => $order->po_number,
                'status' => $order->status instanceof \BackedEnum ? $order->status->value : (string) $order->status,
                'supplier' => self::supplierBrief($order->supplier),
            ] : null,
            'supplier' => self::supplierBrief($supplier),
            'warehouse' => self::warehouseBrief($receipt->warehouse),
            'created_by' => self::userBrief($receipt->createdByUser),
            'posted_by' => self::userBrief($receipt->postedByUser),
            'posted_at' => $receipt->posted_at?->toIso8601String(),
            'is_posted' => $receipt->status === GoodsReceiptStatus::Posted,
            'can_invoice' => false,
            'lines_count' => $receipt->lines_count ?? null,
            'created_at' => (string) $receipt->created_at,
            'updated_at' => (string) $receipt->updated_at,
        ];

        if ($includeLines) {
            $receipt->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
                'lines.lot',
                'lines.purchaseOrderLine',
            ]);
            $payload['lines'] = GoodsReceiptLineResponseData::collectionToArray($receipt->lines);
            $payload['can_invoice'] = $receipt->status === GoodsReceiptStatus::Posted
                && self::linesHaveOpenToInvoice($payload['lines']);
        } else {
            $payload['can_invoice'] = PurchaseInvoiceRules::hasOpenToInvoice($receipt, $invoicedBaseByLineId);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, GoodsReceipt>  $receipts
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $receipts, bool $includeLines = false): array
    {
        if (! $includeLines) {
            $receipts->loadMissing(['lines:id,goods_receipt_id,quantity,base_quantity']);
        }

        $lineIds = [];
        foreach ($receipts as $receipt) {
            if (! $receipt->relationLoaded('lines')) {
                continue;
            }
            foreach ($receipt->lines as $line) {
                $lineIds[] = (int) $line->id;
            }
        }
        $invoiced = PurchaseInvoiceRules::invoicedBaseByGoodsReceiptLineIds($lineIds);

        return $receipts
            ->map(fn (GoodsReceipt $receipt): array => self::fromModel($receipt, $includeLines, $invoiced))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private static function linesHaveOpenToInvoice(array $lines): bool
    {
        foreach ($lines as $line) {
            if (! isset($line['open_to_invoice_quantity'])) {
                continue;
            }
            if (bccomp((string) $line['open_to_invoice_quantity'], '0', 6) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id:string,supplier_code:?string,name:string,is_active:bool}|null
     */
    private static function supplierBrief(?Supplier $supplier): ?array
    {
        if (! $supplier) {
            return null;
        }

        return [
            'id' => $supplier->id,
            'supplier_code' => $supplier->supplier_code,
            'name' => $supplier->name,
            'is_active' => (bool) $supplier->is_active,
        ];
    }

    /**
     * @return array{id:int,name:string,shortcut_name:string,is_active:bool}|null
     */
    private static function warehouseBrief(?Warehouse $warehouse): ?array
    {
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'shortcut_name' => $warehouse->shortcut_name,
            'is_active' => (bool) $warehouse->is_active,
        ];
    }

    /**
     * @return array{id:string,name:string,email:string|null}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
