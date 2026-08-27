<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Modules\Inventory\Stock\Enums\ReplenishmentMethod;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Support\ReplenishmentAlertRules;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\Warehouse\Models\Warehouse;

readonly class PurchasingAlertResponseData
{
    /**
     * @param  array<string, SupplierItem>  $preferredSuppliersByItemId
     */
    public static function fromRow(
        ItemWarehouseReplenishment $rule,
        float $onHand,
        ReplenishmentAlertStatus $status,
        array $preferredSuppliersByItemId,
        float $onOrder = 0.0,
        float $inTransitIn = 0.0,
    ): array {
        $rule->loadMissing([
            'item:id,item_code,name,base_uom_id,track_inventory,allow_purchase,is_active',
            'item.baseUom:id,code,name',
            'warehouse:id,name,shortcut_name,is_active',
        ]);

        $reorderPoint = (float) $rule->reorder_point_qty;
        $safetyStock = (float) $rule->safety_stock_qty;
        $reorderQty = $rule->reorder_qty !== null ? (float) $rule->reorder_qty : null;
        $maxQty = $rule->max_qty !== null ? (float) $rule->max_qty : null;
        $projected = $onHand + $onOrder + $inTransitIn;

        $preferred = $preferredSuppliersByItemId[(string) $rule->item_id] ?? null;

        return [
            'replenishment_id' => $rule->id,
            'item_id' => $rule->item_id,
            'warehouse_id' => $rule->warehouse_id,
            'on_hand_qty' => ReplenishmentAlertRules::formatQty($onHand),
            'on_order_qty' => ReplenishmentAlertRules::formatQty($onOrder),
            'in_transit_in_qty' => ReplenishmentAlertRules::formatQty($inTransitIn),
            'projected_qty' => ReplenishmentAlertRules::formatQty($projected),
            'safety_stock_qty' => (string) $rule->safety_stock_qty,
            'reorder_point_qty' => (string) $rule->reorder_point_qty,
            'reorder_qty' => $rule->reorder_qty !== null ? (string) $rule->reorder_qty : null,
            'max_qty' => $rule->max_qty !== null ? (string) $rule->max_qty : null,
            'replenishment_method' => ReplenishmentMethod::forRule($maxQty)->value,
            'suggested_order_qty' => ReplenishmentAlertRules::suggestedOrderQty(
                $projected,
                $reorderPoint,
                $reorderQty,
                $maxQty,
            ),
            'lead_time_days' => $rule->lead_time_days ?? $preferred?->lead_time_days,
            'status' => $status->value,
            'item' => self::itemBrief($rule->item),
            'warehouse' => self::warehouseBrief($rule->warehouse),
            'preferred_supplier' => self::preferredSupplierBrief($preferred),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function itemBrief(?Item $item): ?array
    {
        if (! $item) {
            return null;
        }

        $item->loadMissing('baseUom:id,code,name');

        return [
            'id' => $item->id,

            'item_code' => $item->item_code,
            'name' => $item->name,
            'base_uom' => $item->baseUom ? [
                'id' => $item->baseUom->id,
                'code' => $item->baseUom->code,
                'name' => $item->baseUom->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
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
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function preferredSupplierBrief(?SupplierItem $supplierItem): ?array
    {
        if (! $supplierItem) {
            return null;
        }

        $supplierItem->loadMissing('supplier:id,supplier_code,name,is_active');

        $supplier = $supplierItem->supplier;
        if (! $supplier instanceof Supplier) {
            return null;
        }

        return [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'supplier_code' => $supplier->supplier_code,
            'supplier_item_id' => $supplierItem->id,
            'supplier_item_code' => $supplierItem->supplier_item_code,
            'last_purchase_price' => $supplierItem->last_purchase_price !== null
                ? (string) $supplierItem->last_purchase_price
                : null,
            'lead_time_days' => $supplierItem->lead_time_days,
        ];
    }
}
