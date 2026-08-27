<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\Enums\InventoryCostingMethod;
use App\Modules\Inventory\Stock\Models\InventoryCostLayer;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Support\InventoryCostingMath as Math;

class InventoryCostingService
{
    public function methodFor(Item $item): InventoryCostingMethod
    {
        if (is_string($item->costing_method) && $item->costing_method !== '') {
            $itemMethod = InventoryCostingMethod::tryFrom($item->costing_method);
            if ($itemMethod !== null) {
                return $itemMethod;
            }
        }

        return CompanySetting::current()->inventory_costing_method;
    }

    /**
     * Standard catalog cost in base UOM (item UOM marked base, else the item's base_uom_id row).
     */
    public function standardUnitCost(Item $item): ?string
    {
        $query = ItemUom::query()->where('item_id', $item->id);

        $base = (clone $query)->where('is_base', true)->first()
            ?? ($item->base_uom_id !== null
                ? (clone $query)->where('uom_id', $item->base_uom_id)->first()
                : null);

        if ($base === null || $base->cost_price === null) {
            return null;
        }

        $cost = Math::money($base->cost_price);

        return bccomp($cost, '0', Math::MONEY_SCALE) < 0 ? null : $cost;
    }

    /**
     * Apply valuation for a posted movement. Caller must hold the balance row lock.
     * Returns unit_cost and signed value_delta to persist on the movement, then syncs layers + balance value.
     *
     * @return array{unit_cost: string, value_delta: string}
     */
    public function apply(
        Item $item,
        StockBalance $balance,
        string $quantityDelta,
        ?string $documentUnitCost,
        int $movementId,
    ): array {
        $method = $this->methodFor($item);
        $inbound = bccomp($quantityDelta, '0', Math::QTY_SCALE) > 0;
        $qty = Math::absQty($quantityDelta);

        if ($inbound) {
            $unitCost = $this->resolveInboundUnitCost($method, $item, $balance, $documentUnitCost);
            $valueDelta = Math::value($qty, $unitCost);
            $this->applyInbound($method, $item, $balance, $qty, $unitCost, $valueDelta, $movementId);

            return [
                'unit_cost' => $unitCost,
                'value_delta' => $valueDelta,
            ];
        }

        $consumed = $this->applyOutbound($method, $item, $balance, $qty, $documentUnitCost);
        $unitCost = $consumed['unit_cost'];
        $valueDelta = bcmul($consumed['value'], '-1', Math::MONEY_SCALE);

        return [
            'unit_cost' => $unitCost,
            'value_delta' => $valueDelta,
        ];
    }

    private function resolveInboundUnitCost(
        InventoryCostingMethod $method,
        Item $item,
        StockBalance $balance,
        ?string $documentUnitCost,
    ): string {
        $document = $this->normalizeCost($documentUnitCost);
        $standard = $this->standardUnitCost($item);
        $average = Math::money($balance->unit_cost);

        return match ($method) {
            InventoryCostingMethod::Standard => $this->requireCost(
                $standard ?? $document,
                'Set a cost price on the item base UOM before receiving stock under standard costing.',
                'STOCK_STANDARD_COST_REQUIRED',
            ),
            InventoryCostingMethod::MovingAverage => $this->requireCost(
                $document ?? (bccomp($average, '0', Math::MONEY_SCALE) > 0 ? $average : null) ?? $standard,
                'Enter a unit cost for this inbound movement (or set the item cost price).',
                'STOCK_UNIT_COST_REQUIRED',
            ),
            InventoryCostingMethod::Fifo => $this->requireCost(
                $document ?? $standard,
                'Enter a unit cost for this inbound movement (or set the item cost price).',
                'STOCK_UNIT_COST_REQUIRED',
            ),
            InventoryCostingMethod::Actual => $this->requireCost(
                $document,
                'Actual costing requires a unit cost on every inbound movement.',
                'STOCK_UNIT_COST_REQUIRED',
            ),
        };
    }

    /**
     * @return array{unit_cost: string, value: string}
     */
    private function applyOutbound(
        InventoryCostingMethod $method,
        Item $item,
        StockBalance $balance,
        string $qty,
        ?string $documentUnitCost,
    ): array {
        $oldQty = Math::qty($balance->quantity);
        $oldValue = Math::money($balance->inventory_value);

        if ($method->usesLayers()) {
            $consumedValue = $this->consumeLayers(
                $item,
                (int) $balance->warehouse_id,
                $qty,
                $documentUnitCost,
                $balance->lot_id !== null ? (int) $balance->lot_id : null,
            );
            $newQty = bcsub($oldQty, $qty, Math::QTY_SCALE);
            $newValue = bcsub($oldValue, $consumedValue, Math::MONEY_SCALE);
            if (bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
                $newValue = Math::money(0);
            }
            $this->writeBalanceValue(
                $balance,
                $newQty,
                $newValue,
                Math::unitCostFromValue($qty, $consumedValue),
            );

            return [
                'unit_cost' => Math::unitCostFromValue($qty, $consumedValue),
                'value' => $consumedValue,
            ];
        }

        $unitCost = match ($method) {
            InventoryCostingMethod::Standard => $this->requireCost(
                $this->standardUnitCost($item) ?? $this->normalizeCost(Math::money($balance->unit_cost)),
                'Set a cost price on the item base UOM before issuing stock under standard costing.',
                'STOCK_STANDARD_COST_REQUIRED',
            ),
            InventoryCostingMethod::MovingAverage => Math::money($balance->unit_cost),
            default => Math::money($balance->unit_cost),
        };

        $consumedValue = Math::value($qty, $unitCost);
        $newQty = bcsub($oldQty, $qty, Math::QTY_SCALE);
        $newValue = bcsub($oldValue, $consumedValue, Math::MONEY_SCALE);
        if (bccomp($newQty, '0', Math::QTY_SCALE) === 0) {
            $newValue = Math::money(0);
        }
        if (bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
            $newValue = Math::money(0);
        }
        $this->writeBalanceValue($balance, $newQty, $newValue, $unitCost);

        return [
            'unit_cost' => $unitCost,
            'value' => $consumedValue,
        ];
    }

    private function applyInbound(
        InventoryCostingMethod $method,
        Item $item,
        StockBalance $balance,
        string $qty,
        string $unitCost,
        string $valueDelta,
        int $movementId,
    ): void {
        $oldQty = Math::qty($balance->quantity);
        $oldValue = Math::money($balance->inventory_value);
        $newQty = bcadd($oldQty, $qty, Math::QTY_SCALE);

        if (bccomp($oldQty, '0', Math::QTY_SCALE) <= 0) {
            $recovered = Math::inboundAfterNonPositiveOnHand($oldQty, $qty, $unitCost);
            $layerQty = $recovered['qty'];
            if (bccomp($layerQty, '0', Math::QTY_SCALE) < 0) {
                $layerQty = Math::qty(0);
            }

            if ($method->usesLayers() && bccomp($layerQty, '0', Math::QTY_SCALE) > 0) {
                InventoryCostLayer::query()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $balance->warehouse_id,
                    'lot_id' => $balance->lot_id,
                    'source_movement_id' => $movementId,
                    'quantity_remaining' => $layerQty,
                    'unit_cost' => $unitCost,
                ]);
            }

            $this->writeBalanceValue($balance, $recovered['qty'], $recovered['value'], $unitCost);

            return;
        }

        if ($method->usesLayers()) {
            InventoryCostLayer::query()->create([
                'item_id' => $item->id,
                'warehouse_id' => $balance->warehouse_id,
                'lot_id' => $balance->lot_id,
                'source_movement_id' => $movementId,
                'quantity_remaining' => $qty,
                'unit_cost' => $unitCost,
            ]);
            $newValue = bcadd($oldValue, $valueDelta, Math::MONEY_SCALE);
            $this->writeBalanceValue($balance, $newQty, $newValue);

            return;
        }

        if ($method === InventoryCostingMethod::MovingAverage) {
            $newValue = bcadd($oldValue, $valueDelta, Math::MONEY_SCALE);
            $this->writeBalanceValue($balance, $newQty, $newValue);

            return;
        }

        $standard = $unitCost;
        $newValue = Math::value($newQty, $standard);
        $this->writeBalanceValue($balance, $newQty, $newValue);
    }

    private function consumeLayers(
        Item $item,
        int $warehouseId,
        string $qty,
        ?string $preferredUnitCost,
        ?int $lotId,
    ): string {
        $remaining = $qty;
        $consumedValue = Math::money(0);

        $layers = InventoryCostLayer::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouseId)
            ->when(
                $lotId === null,
                fn ($query) => $query->whereNull('lot_id'),
                fn ($query) => $query->where('lot_id', $lotId),
            )
            ->where('quantity_remaining', '>', 0)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($preferredUnitCost !== null) {
            $preferred = Math::money($preferredUnitCost);
            $layers = $layers->sortBy(function (InventoryCostLayer $layer) use ($preferred): int {
                return bccomp(Math::money($layer->unit_cost), $preferred, Math::MONEY_SCALE) === 0 ? 0 : 1;
            })->values();
        }

        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', Math::QTY_SCALE) <= 0) {
                break;
            }

            $layerQty = Math::qty($layer->quantity_remaining);
            $take = bccomp($layerQty, $remaining, Math::QTY_SCALE) <= 0 ? $layerQty : $remaining;
            $consumedValue = bcadd($consumedValue, Math::value($take, Math::money($layer->unit_cost)), Math::MONEY_SCALE);
            $left = bcsub($layerQty, $take, Math::QTY_SCALE);
            $layer->update(['quantity_remaining' => $left]);
            $remaining = bcsub($remaining, $take, Math::QTY_SCALE);
        }

        if (bccomp($remaining, '0', Math::QTY_SCALE) > 0) {
            abort(422, 'Insufficient cost layers for this issue. Recost or adjust opening stock.', [
                'X-Error-Code' => 'STOCK_COST_LAYER_INSUFFICIENT',
            ]);
        }

        return $consumedValue;
    }

    private function writeBalanceValue(
        StockBalance $balance,
        string $qty,
        string $value,
        ?string $fallbackUnitCost = null,
    ): void {
        if (bccomp($qty, '0', Math::QTY_SCALE) < 0) {
            $value = Math::money(0);
            $unitCost = $fallbackUnitCost ?? Math::money($balance->unit_cost);
        } elseif (bccomp($qty, '0', Math::QTY_SCALE) === 0) {
            $value = Math::money(0);
            $unitCost = Math::money(0);
        } else {
            $unitCost = Math::unitCostFromValue($qty, $value);
        }

        $balance->forceFill([
            'unit_cost' => $unitCost,
            'inventory_value' => $value,
        ])->save();
    }

    private function normalizeCost(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cost = Math::money($value);
        if (bccomp($cost, '0', Math::MONEY_SCALE) < 0) {
            abort(422, 'Unit cost cannot be negative.', ['X-Error-Code' => 'STOCK_UNIT_COST_INVALID']);
        }

        return $cost;
    }

    private function requireCost(?string $cost, string $message, string $code): string
    {
        if ($cost === null) {
            abort(422, $message, ['X-Error-Code' => $code]);
        }

        return $cost;
    }
}
