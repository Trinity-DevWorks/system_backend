<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\Support\InventoryCostingMath as Math;
use App\Modules\Supplier\Models\SupplierItem;

/**
 * Suggests a unit cost for inbound document lines (GRN / opening / +adjustment).
 *
 * Priority (first positive wins), always returned in the selected line UOM:
 * 1. Supplier last purchase price (base) — when supplier_id is set
 * 2. Warehouse balance unit_cost (base) — when warehouse has positive qty
 * 3. Catalog: selected UOM cost_price, else base standard × conversion factor
 *
 * @return array{unit_cost: string|null, source: string|null}
 */
class SuggestedUnitCostService
{
    public function __construct(
        private readonly InventoryCostingService $inventoryCostingService,
        private readonly StockBalanceService $stockBalanceService,
    ) {}

    public function suggest(
        string $itemId,
        ?int $warehouseId = null,
        ?string $supplierId = null,
        ?int $itemUomId = null,
        ?int $lotId = null,
    ): array {
        $item = Item::query()->find($itemId);
        if ($item === null) {
            return $this->empty();
        }

        $factor = $this->conversionFactor($item, $itemUomId);

        if ($supplierId !== null && $supplierId !== '') {
            $lastPurchase = $this->lastPurchaseBaseCost($supplierId, $itemId);
            if ($lastPurchase !== null) {
                return $this->result($this->toLineCost($lastPurchase, $factor), 'last_purchase');
            }
        }

        if ($warehouseId !== null && $warehouseId > 0) {
            $balanceCost = $this->balanceBaseCost($itemId, $warehouseId, $lotId);
            if ($balanceCost !== null) {
                return $this->result($this->toLineCost($balanceCost, $factor), 'balance');
            }
        }

        $catalog = $this->catalogLineCost($item, $itemUomId, $factor);
        if ($catalog !== null) {
            return $this->result($catalog, 'standard');
        }

        return $this->empty();
    }

    /**
     * @return array{unit_cost: null, source: null}
     */
    private function empty(): array
    {
        return ['unit_cost' => null, 'source' => null];
    }

    /**
     * @return array{unit_cost: string|null, source: string|null}
     */
    private function result(?string $unitCost, string $source): array
    {
        if ($unitCost === null || bccomp($unitCost, '0', Math::MONEY_SCALE) < 0) {
            return $this->empty();
        }

        return ['unit_cost' => $unitCost, 'source' => $source];
    }

    private function lastPurchaseBaseCost(string $supplierId, string $itemId): ?string
    {
        $price = SupplierItem::query()
            ->where('supplier_id', $supplierId)
            ->where('item_id', $itemId)
            ->value('last_purchase_price');

        if ($price === null) {
            return null;
        }

        $normalized = Math::money($price);

        return bccomp($normalized, '0', Math::MONEY_SCALE) > 0 ? $normalized : null;
    }

    private function balanceBaseCost(string $itemId, int $warehouseId, ?int $lotId): ?string
    {
        $balance = $this->stockBalanceService->findForItemWarehouse($itemId, $warehouseId, $lotId);
        if ($balance === null) {
            return null;
        }

        $qty = Math::qty($balance->quantity);
        if (bccomp($qty, '0', Math::QTY_SCALE) <= 0) {
            return null;
        }

        $cost = Math::money($balance->unit_cost);

        return bccomp($cost, '0', Math::MONEY_SCALE) > 0 ? $cost : null;
    }

    private function catalogLineCost(Item $item, ?int $itemUomId, string $factor): ?string
    {
        if ($itemUomId !== null) {
            $row = ItemUom::query()
                ->where('item_id', $item->id)
                ->whereKey($itemUomId)
                ->first();
            if ($row !== null && $row->cost_price !== null) {
                $direct = Math::money($row->cost_price);
                if (bccomp($direct, '0', Math::MONEY_SCALE) >= 0) {
                    return $direct;
                }
            }
        }

        $standard = $this->inventoryCostingService->standardUnitCost($item);
        if ($standard === null) {
            return null;
        }

        return $this->toLineCost($standard, $factor);
    }

    private function conversionFactor(Item $item, ?int $itemUomId): string
    {
        if ($itemUomId === null) {
            return '1';
        }

        $row = ItemUom::query()
            ->where('item_id', $item->id)
            ->whereKey($itemUomId)
            ->first();

        if ($row === null) {
            return '1';
        }

        $factor = (string) $row->conversion_factor;
        if (bccomp($factor, '0', 6) <= 0) {
            return '1';
        }

        return $factor;
    }

    private function toLineCost(string $baseCost, string $factor): string
    {
        if (bccomp($factor, '1', 6) === 0) {
            return Math::money($baseCost);
        }

        return Math::money(bcmul($baseCost, $factor, Math::MONEY_SCALE));
    }
}
