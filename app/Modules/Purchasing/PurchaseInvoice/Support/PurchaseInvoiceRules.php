<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Support;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Purchasing\PurchaseInvoice\Enums\PurchaseInvoiceStatus;
use App\Modules\Purchasing\PurchaseInvoice\Models\PurchaseInvoice;
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
}
