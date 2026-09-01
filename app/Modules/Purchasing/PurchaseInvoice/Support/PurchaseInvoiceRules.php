<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Support;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Purchasing\PurchaseInvoice\Enums\PurchaseInvoiceStatus;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoice;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoiceLine;
use App\Modules\Supplier\Models\Supplier;

final class PurchaseInvoiceRules
{
    public static function assertDraft(PurchaseInvoice $invoice): void
    {
        if ($invoice->status !== PurchaseInvoiceStatus::Draft) {
            abort(422, 'Only draft purchase invoices can be modified.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_NOT_DRAFT',
            ]);
        }
    }

    public static function assertPostable(PurchaseInvoice $invoice): void
    {
        self::assertDraft($invoice);

        if ($invoice->lines()->count() === 0) {
            abort(422, 'Cannot post a purchase invoice without lines.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_NO_LINES',
            ]);
        }
    }

    public static function assertSupplier(string $supplierId): Supplier
    {
        $supplier = Supplier::query()->findOrFail($supplierId);

        if (! $supplier->is_active) {
            abort(422, 'Supplier must be active.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_SUPPLIER_INACTIVE',
            ]);
        }

        return $supplier;
    }

    public static function assertCurrency(int $currencyId): Currency
    {
        $currency = Currency::query()->findOrFail($currencyId);

        if (! $currency->is_active) {
            abort(422, 'Currency must be active.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_CURRENCY_INACTIVE',
            ]);
        }

        return $currency;
    }

    public static function assertPurchasableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Item must be active.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_ITEM_INACTIVE',
            ]);
        }

        if (! $item->allow_purchase) {
            abort(422, 'Item is not purchasable.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_ITEM_NOT_PURCHASABLE',
            ]);
        }
    }

    /**
     * Posted GR only; supplier comes from the receipt or its purchase order.
     */
    public static function assertInvoiceableGoodsReceipt(GoodsReceipt $receipt): GoodsReceipt
    {
        if ($receipt->status !== GoodsReceiptStatus::Posted) {
            abort(422, 'Only posted goods receipts can be invoiced.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_GR_NOT_POSTED',
            ]);
        }

        $supplierId = $receipt->supplier_id ?? $receipt->purchaseOrder?->supplier_id;
        if ($supplierId === null || $supplierId === '') {
            abort(422, 'This goods receipt has no supplier to invoice.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_GR_NO_SUPPLIER',
            ]);
        }

        return $receipt;
    }

    /**
     * Remaining qty in the GR line UOM: received minus already invoiced (draft + posted).
     */
    public static function openQuantity(GoodsReceiptLine $line, ?string $excludeInvoiceId = null): string
    {
        $invoicedBase = self::invoicedBaseQuantity((int) $line->id, $excludeInvoiceId);

        return self::openQuantityFromBases($line, $invoicedBase);
    }

    /**
     * Remaining qty in the GR line UOM using a preloaded invoiced-base map.
     *
     * @param  array<int, string>  $invoicedBaseByLineId
     */
    public static function openQuantityUsingMap(GoodsReceiptLine $line, array $invoicedBaseByLineId): string
    {
        $invoicedBase = $invoicedBaseByLineId[(int) $line->id] ?? '0';

        return self::openQuantityFromBases($line, (string) $invoicedBase);
    }

    public static function openBaseQuantity(GoodsReceiptLine $line, ?string $excludeInvoiceId = null): string
    {
        $receivedBase = (string) $line->base_quantity;
        $invoicedBase = self::invoicedBaseQuantity((int) $line->id, $excludeInvoiceId);
        $openBase = bcsub($receivedBase, $invoicedBase, 6);

        return bccomp($openBase, '0', 6) < 0 ? '0.000000' : $openBase;
    }

    public static function invoicedBaseQuantity(int $goodsReceiptLineId, ?string $excludeInvoiceId = null): string
    {
        $query = PurchaseInvoiceLine::query()
            ->where('goods_receipt_line_id', $goodsReceiptLineId);

        if ($excludeInvoiceId !== null && $excludeInvoiceId !== '') {
            $query->where('purchase_invoice_id', '!=', $excludeInvoiceId);
        }

        $sum = $query->sum('base_quantity');

        return number_format((float) $sum, 6, '.', '');
    }

    /**
     * @param  list<int>  $goodsReceiptLineIds
     * @return array<int, string> line id => invoiced base qty
     */
    public static function invoicedBaseByGoodsReceiptLineIds(array $goodsReceiptLineIds, ?string $excludeInvoiceId = null): array
    {
        if ($goodsReceiptLineIds === []) {
            return [];
        }

        $query = PurchaseInvoiceLine::query()
            ->whereIn('goods_receipt_line_id', $goodsReceiptLineIds)
            ->selectRaw('goods_receipt_line_id, COALESCE(SUM(base_quantity), 0) as invoiced_base')
            ->groupBy('goods_receipt_line_id');

        if ($excludeInvoiceId !== null && $excludeInvoiceId !== '') {
            $query->where('purchase_invoice_id', '!=', $excludeInvoiceId);
        }

        /** @var array<int, string> $out */
        $out = [];
        foreach ($query->get() as $row) {
            $out[(int) $row->goods_receipt_line_id] = number_format((float) $row->invoiced_base, 6, '.', '');
        }

        return $out;
    }

    /**
     * Posted GR with at least one line that still has remaining qty to invoice.
     *
     * @param  array<int, string>|null  $invoicedBaseByLineId
     */
    public static function hasOpenToInvoice(GoodsReceipt $receipt, ?array $invoicedBaseByLineId = null): bool
    {
        if ($receipt->status !== GoodsReceiptStatus::Posted) {
            return false;
        }

        $lines = $receipt->relationLoaded('lines')
            ? $receipt->lines
            : $receipt->lines()->get(['id', 'goods_receipt_id', 'quantity', 'base_quantity']);

        if ($lines->isEmpty()) {
            return false;
        }

        $map = $invoicedBaseByLineId;
        if ($map === null) {
            $ids = $lines
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            $map = self::invoicedBaseByGoodsReceiptLineIds($ids);
        }

        foreach ($lines as $line) {
            if (! $line instanceof GoodsReceiptLine) {
                continue;
            }
            if (bccomp(self::openQuantityUsingMap($line, $map), '0', 6) > 0) {
                return true;
            }
        }

        return false;
    }

    private static function openQuantityFromBases(GoodsReceiptLine $line, string $invoicedBase): string
    {
        $receivedBase = (string) $line->base_quantity;
        $openBase = bcsub($receivedBase, $invoicedBase, 6);
        if (bccomp($openBase, '0', 6) <= 0) {
            return '0.000000';
        }
        if (bccomp($receivedBase, '0', 6) === 0) {
            return '0.000000';
        }

        $receivedQty = (string) $line->quantity;

        return bcmul($openBase, bcdiv($receivedQty, $receivedBase, 8), 6);
    }
}
