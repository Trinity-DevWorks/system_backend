<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Support;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\BundleItemRules;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class SalesInvoiceRules
{
    public static function assertDraft(SalesInvoice $invoice): void
    {
        if ($invoice->status !== SalesInvoiceStatus::Draft) {
            abort(422, 'Only draft sales invoices can be modified.', ['X-Error-Code' => 'SALES_INVOICE_NOT_DRAFT']);
        }
    }

    public static function assertPostable(SalesInvoice $invoice): void
    {
        self::assertDraft($invoice);

        if ($invoice->lines()->count() === 0) {
            abort(422, 'Cannot post a sales invoice without lines.', ['X-Error-Code' => 'SALES_INVOICE_NO_LINES']);
        }
    }

    public static function assertCustomer(string $customerId): Customer
    {
        $customer = Customer::query()->findOrFail($customerId);

        if ($customer->status === CustomerStatus::Blacklisted) {
            abort(422, 'Customer is blacklisted.', ['X-Error-Code' => 'SALES_INVOICE_CUSTOMER_BLACKLISTED']);
        }

        if ($customer->status !== CustomerStatus::Active && ! $customer->is_system) {
            abort(422, 'Customer must be active.', ['X-Error-Code' => 'SALES_INVOICE_CUSTOMER_INACTIVE']);
        }

        return $customer;
    }

    public static function assertWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            abort(422, 'Warehouse must be active.', ['X-Error-Code' => 'SALES_INVOICE_WAREHOUSE_INACTIVE']);
        }

        app(WarehouseService::class)->assertVisible($warehouse);
    }

    public static function assertSellableItem(Item $item): void
    {
        if (! $item->is_active) {
            abort(422, 'Item must be active.', ['X-Error-Code' => 'SALES_INVOICE_ITEM_INACTIVE']);
        }

        if (! $item->allow_sale) {
            abort(422, 'Item is not sellable.', ['X-Error-Code' => 'SALES_INVOICE_ITEM_NOT_SELLABLE']);
        }
    }

    public static function isBundleItem(Item $item): bool
    {
        $item->loadMissing('itemType:id,code');

        return $item->itemType !== null
            && strtoupper((string) $item->itemType->code) === BundleItemRules::BUNDLE_TYPE_CODE;
    }
}
