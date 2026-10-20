<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Support;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Warehouse\Models\Warehouse;
use App\Modules\Warehouse\Services\WarehouseService;

final class SalesOrderRules
{
    public static function assertDraft(SalesOrder $order): void
    {
        if ($order->status !== SalesOrderStatus::Draft) {
            abort(422, 'Only a draft sales order can be modified.', ['X-Error-Code' => 'SALES_ORDER_NOT_DRAFT']);
        }
    }

    public static function assertCustomer(string $customerId): Customer
    {
        $customer = Customer::query()->findOrFail($customerId);

        if ($customer->status === CustomerStatus::Blacklisted) {
            abort(422, 'Customer is blacklisted.', ['X-Error-Code' => 'SALES_ORDER_CUSTOMER_BLACKLISTED']);
        }

        if ($customer->status !== CustomerStatus::Active && ! $customer->is_system) {
            abort(422, 'Customer must be active.', ['X-Error-Code' => 'SALES_ORDER_CUSTOMER_INACTIVE']);
        }

        return $customer;
    }

    public static function assertWarehouse(int $warehouseId): void
    {
        $warehouse = Warehouse::query()->findOrFail($warehouseId);

        if (! $warehouse->is_active) {
            abort(422, 'Warehouse must be active.', ['X-Error-Code' => 'SALES_ORDER_WAREHOUSE_INACTIVE']);
        }

        app(WarehouseService::class)->assertVisible($warehouse);
    }
}
