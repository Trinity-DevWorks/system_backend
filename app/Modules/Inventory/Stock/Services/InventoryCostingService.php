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

        $cost = Math::unitCost($base->cost_price);

        return bccomp($cost, '0', Math::MONEY_SCALE) < 0 ? null : $cost;
    }

    /**
     * Apply valuation for a posted movement. Caller must hold the balance row lock.
     * Returns unit_cost and signed value_delta to persist on the movement, then syncs layers + balance value.
     * An inbound that fills negative stock also returns how much it filled and its cost correction.
     *
     * @return array{unit_cost: string, value_delta: string, cost_correction: string, negative_fill_quantity: string}
     */
    public function apply(
        Item $item,
        StockBalance $balance,
        string $quantityDelta,
        ?string $documentUnitCost,
        int $movementId,
        ?string $explicitValue = null,
    ): array {
        $method = $this->methodFor($item);
        $inbound = bccomp($quantityDelta, '0', Math::QTY_SCALE) > 0;
        $qty = Math::absQty($quantityDelta);

        if ($inbound) {
            $unitCost = $this->resolveInboundUnitCost($method, $item, $balance, $documentUnitCost);
            $explicit = $this->normalizeCost($explicitValue);
            $useExplicit = $method !== InventoryCostingMethod::Standard
                && $explicit !== null
                && bccomp($explicit, '0', Math::MONEY_SCALE) > 0;
            if ($useExplicit) {
                $valueDelta = $explicit;
                $unitCost = Math::unitCostFromValue($qty, $valueDelta);
            } else {
                $valueDelta = Math::value($qty, $unitCost);
            }
            $fill = $this->applyInbound($method, $item, $balance, $qty, $unitCost, $valueDelta, $movementId);

            return [
                'unit_cost' => $unitCost,
                'value_delta' => $valueDelta,
                'cost_correction' => $fill['correction'],
                'negative_fill_quantity' => $fill['quantity'],
            ];
        }

        $consumed = $this->applyOutbound($method, $item, $balance, $qty, $documentUnitCost);
        $unitCost = $consumed['unit_cost'];
        $valueDelta = bcmul($consumed['value'], '-1', Math::MONEY_SCALE);

        return [
            'unit_cost' => $unitCost,
            'value_delta' => $valueDelta,
            'cost_correction' => Math::money(0),
            'negative_fill_quantity' => Math::qty(0),
        ];
    }

    /**
     * Value-only revaluation. Caller must hold the balance row lock. Quantity is unchanged.
     *
     * @return array{unit_cost: string, value_delta: string}
     */
    public function revalue(
        Item $item,
        StockBalance $balance,
        string $newUnitCost,
        int $movementId,
    ): array {
        $newUnitCost = $this->normalizeCost($newUnitCost) ?? Math::unitCost(0);
        $qty = Math::qty($balance->quantity);
        $oldValue = Math::money($balance->inventory_value);
        $oldUnitCost = Math::unitCost($balance->unit_cost);

        if (bccomp($qty, '0', Math::QTY_SCALE) <= 0) {
            abort(422, 'Cannot revalue zero or negative on-hand quantity.', [
                'X-Error-Code' => 'STOCK_REVALUATION_NO_QTY',
            ]);
        }

        if (bccomp($newUnitCost, $oldUnitCost, Math::UNIT_COST_SCALE) === 0) {
            abort(422, 'New unit cost must differ from the current balance cost.', [
                'X-Error-Code' => 'STOCK_REVALUATION_NO_CHANGE',
            ]);
        }

        $newValue = Math::value($qty, $newUnitCost);
        $valueDelta = bcsub($newValue, $oldValue, Math::MONEY_SCALE);
        $method = $this->methodFor($item);

        if ($method->usesLayers()) {
            InventoryCostLayer::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $balance->warehouse_id)
                ->when(
                    $balance->lot_id === null,
                    fn ($query) => $query->whereNull('lot_id'),
                    fn ($query) => $query->where('lot_id', $balance->lot_id),
                )
                ->delete();

            InventoryCostLayer::query()->create([
                'item_id' => $item->id,
                'warehouse_id' => $balance->warehouse_id,
                'lot_id' => $balance->lot_id,
                'source_movement_id' => $movementId,
                'quantity_remaining' => $qty,
                'unit_cost' => $newUnitCost,
            ]);
        }

        $balance->forceFill([
            'unit_cost' => $newUnitCost,
            'inventory_value' => $newValue,
        ])->save();

        return [
            'unit_cost' => $newUnitCost,
            'value_delta' => $valueDelta,
        ];
    }

    /**
     * Reverse a revaluation movement on a locked balance. Does not restore the
     * pre-revaluation FIFO/Actual layer split — recreates one layer at old cost.
     */
    public function unwindRevalue(
        Item $item,
        StockBalance $balance,
        string $originalValueDelta,
        string $oldUnitCost,
        int $originalMovementId,
        int $reverseMovementId,
    ): void {
        $qty = Math::qty($balance->quantity);
        $oldValue = Math::money($balance->inventory_value);
        $newValue = bcsub($oldValue, Math::money($originalValueDelta), Math::MONEY_SCALE);
        $restoredUnitCost = $this->normalizeCost($oldUnitCost) ?? Math::unitCost(0);

        if (bccomp($qty, '0', Math::QTY_SCALE) <= 0) {
            $newValue = Math::money(0);
            $restoredUnitCost = Math::unitCost(0);
        } elseif (bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
            $newValue = Math::money(0);
        }

        $method = $this->methodFor($item);
        if ($method->usesLayers()) {
            InventoryCostLayer::query()
                ->where('source_movement_id', $originalMovementId)
                ->delete();

            if (bccomp($qty, '0', Math::QTY_SCALE) > 0) {
                InventoryCostLayer::query()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $balance->warehouse_id,
                    'lot_id' => $balance->lot_id,
                    'source_movement_id' => $reverseMovementId,
                    'quantity_remaining' => $qty,
                    'unit_cost' => $restoredUnitCost,
                ]);
            }
        }

        $balance->forceFill([
            'unit_cost' => $restoredUnitCost,
            'inventory_value' => $newValue,
        ])->save();
    }

    /**
     * Undo one posted movement's quantity and value on a locked balance row.
     * Inbound layers that were already issued cannot be unwound.
     */
    public function unwind(
        Item $item,
        StockBalance $balance,
        string $originalQuantityDelta,
        string $originalValueDelta,
        ?string $originalUnitCost,
        int $originalMovementId,
        int $reverseMovementId,
        string $originalCostCorrection = '0',
        string $originalNegativeFill = '0',
    ): void {
        $method = $this->methodFor($item);
        $reverseQty = bcmul($originalQuantityDelta, '-1', Math::QTY_SCALE);
        $oldQty = Math::qty($balance->quantity);
        $newQty = bcadd($oldQty, $reverseQty, Math::QTY_SCALE);

        if (bccomp($originalQuantityDelta, '0', Math::QTY_SCALE) > 0 && $method->usesLayers()) {
            // The part that filled negative stock never became a layer.
            $layerQty = bcsub(Math::qty($originalQuantityDelta), Math::qty($originalNegativeFill), Math::QTY_SCALE);
            if (bccomp($layerQty, '0', Math::QTY_SCALE) > 0) {
                $this->releaseInboundLayer($originalMovementId, $layerQty);
            }
        }

        $oldValue = Math::money($balance->inventory_value);
        // An inbound that filled negative stock added its value less the cost correction it posted.
        $added = bcsub(Math::money($originalValueDelta), Math::money($originalCostCorrection), Math::MONEY_SCALE);
        $newValue = bcsub($oldValue, $added, Math::MONEY_SCALE);

        if (bccomp($newQty, '0', Math::QTY_SCALE) < 0) {
            // Back below zero: the value stays negative (what the missing quantity was issued at).
            if (bccomp($newValue, '0', Math::MONEY_SCALE) > 0) {
                $newValue = Math::money(0);
            }
        } elseif (bccomp($newQty, '0', Math::QTY_SCALE) === 0) {
            $newValue = Math::money(0);
        } elseif (bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
            $newValue = Math::money(0);
        } elseif ($method === InventoryCostingMethod::Standard) {
            $standard = $this->standardUnitCost($item) ?? $this->normalizeCost($originalUnitCost);
            if ($standard !== null) {
                $newValue = Math::value($newQty, $standard);
            }
        }

        if (bccomp($originalQuantityDelta, '0', Math::QTY_SCALE) < 0 && $method->usesLayers()) {
            $restoreQty = Math::absQty($originalQuantityDelta);
            $unitCost = $this->normalizeCost($originalUnitCost) ?? Math::unitCost(0);
            if (bccomp($restoreQty, '0', Math::QTY_SCALE) > 0) {
                InventoryCostLayer::query()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $balance->warehouse_id,
                    'lot_id' => $balance->lot_id,
                    'source_movement_id' => $reverseMovementId,
                    'quantity_remaining' => $restoreQty,
                    'unit_cost' => $unitCost,
                ]);
            }
        }

        $this->writeBalanceValue($balance, $newQty, $newValue, $this->normalizeCost($originalUnitCost));
    }

    /**
     * How much of a receipt is still in stock, used to split a landed cost between stock and cost of sales.
     * Layered methods: what is left of the receipt's own layer (falls back to on-hand when the layer was
     * rebuilt by a revaluation). Moving average: on-hand quantity, capped at the received quantity.
     * Standard: null, the whole amount is a price variance.
     */
    public function landedCostBasis(Item $item, StockBalance $balance, ?int $sourceMovementId, string $receivedQty): ?string
    {
        $method = $this->methodFor($item);
        if ($method === InventoryCostingMethod::Standard) {
            return null;
        }

        $received = Math::qty($receivedQty);
        $onHand = Math::qty($balance->quantity);
        if (bccomp($onHand, '0', Math::QTY_SCALE) < 0) {
            $onHand = Math::qty(0);
        }

        $layer = $method->usesLayers() && $sourceMovementId !== null
            ? InventoryCostLayer::query()->where('source_movement_id', $sourceMovementId)->first()
            : null;
        $available = $layer !== null ? Math::qty($layer->quantity_remaining) : $onHand;
        if ($layer !== null && bccomp($available, $onHand, Math::QTY_SCALE) > 0) {
            $available = $onHand;
        }

        return bccomp($available, $received, Math::QTY_SCALE) < 0 ? $available : $received;
    }

    /**
     * Add (or, when negative, remove) value from stock already on hand without moving quantity.
     * Layered methods put it on the receipt's own layer when it still exists, else spread it over the
     * balance's layers by quantity. Caller must hold the balance row lock.
     */
    public function addLandedValue(Item $item, StockBalance $balance, string $amount, ?int $sourceMovementId): void
    {
        $amount = Math::money($amount);
        $qty = Math::qty($balance->quantity);
        if (bccomp($amount, '0', Math::MONEY_SCALE) === 0 || bccomp($qty, '0', Math::QTY_SCALE) <= 0) {
            return;
        }

        if ($this->methodFor($item)->usesLayers()) {
            $scope = InventoryCostLayer::query()
                ->where('item_id', $item->id)
                ->where('warehouse_id', $balance->warehouse_id)
                ->when(
                    $balance->lot_id === null,
                    fn ($query) => $query->whereNull('lot_id'),
                    fn ($query) => $query->where('lot_id', $balance->lot_id),
                )
                ->where('quantity_remaining', '>', 0);

            $own = $sourceMovementId !== null
                ? (clone $scope)->where('source_movement_id', $sourceMovementId)->lockForUpdate()->first()
                : null;
            $layers = $own !== null ? collect([$own]) : (clone $scope)->lockForUpdate()->get();
            $layerQty = $layers->reduce(fn (string $sum, InventoryCostLayer $l): string => bcadd($sum, Math::qty($l->quantity_remaining), Math::QTY_SCALE), '0');

            if (bccomp($layerQty, '0', Math::QTY_SCALE) > 0) {
                $perUnit = bcdiv($amount, $layerQty, Math::UNIT_COST_SCALE + 4);
                foreach ($layers as $layer) {
                    $newCost = bcadd(Math::unitCost($layer->unit_cost), $perUnit, Math::UNIT_COST_SCALE + 4);
                    $layer->update(['unit_cost' => Math::unitCost(bccomp($newCost, '0', Math::UNIT_COST_SCALE) < 0 ? '0' : $newCost)]);
                }
            }
        }

        $newValue = bcadd(Math::money($balance->inventory_value), $amount, Math::MONEY_SCALE);
        if (bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
            $newValue = Math::money(0);
        }
        $this->writeBalanceValue($balance, $qty, $newValue);
    }

    private function releaseInboundLayer(int $sourceMovementId, string $qty): void
    {
        $layer = InventoryCostLayer::query()
            ->where('source_movement_id', $sourceMovementId)
            ->lockForUpdate()
            ->first();

        if ($layer === null) {
            return;
        }

        $remaining = Math::qty($layer->quantity_remaining);
        if (bccomp($remaining, $qty, Math::QTY_SCALE) < 0) {
            abort(422, 'Stock from this document has already been issued and cannot be reversed.', [
                'X-Error-Code' => 'DOCUMENT_REVERSE_STOCK_CONSUMED',
            ]);
        }

        $layer->update([
            'quantity_remaining' => bcsub($remaining, $qty, Math::QTY_SCALE),
        ]);
    }

    private function resolveInboundUnitCost(
        InventoryCostingMethod $method,
        Item $item,
        StockBalance $balance,
        ?string $documentUnitCost,
    ): string {
        $document = $this->normalizeCost($documentUnitCost);
        $standard = $this->standardUnitCost($item);
        $average = Math::unitCost($balance->unit_cost);

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

        $newQty = bcsub($oldQty, $qty, Math::QTY_SCALE);

        if ($method->usesLayers()) {
            // The part going below zero has no layer: it is costed at the last known cost.
            $belowZero = bccomp($newQty, '0', Math::QTY_SCALE) < 0
                ? (bccomp($oldQty, '0', Math::QTY_SCALE) > 0 ? Math::absQty($newQty) : $qty)
                : Math::qty(0);
            $consumed = $this->consumeLayers(
                $item,
                $balance,
                $qty,
                $documentUnitCost,
                $belowZero,
            );
            $consumedValue = $consumed['value'];
            $newValue = bcsub($oldValue, $consumedValue, Math::MONEY_SCALE);
            if (bccomp($newQty, '0', Math::QTY_SCALE) >= 0 && bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
                $newValue = Math::money(0);
            }
            $this->writeBalanceValue(
                $balance,
                $newQty,
                $newValue,
                $consumed['last_cost'],
            );

            return [
                'unit_cost' => Math::unitCostFromValue($qty, $consumedValue),
                'value' => $consumedValue,
            ];
        }

        $unitCost = match ($method) {
            InventoryCostingMethod::Standard => $this->requireCost(
                $this->standardUnitCost($item) ?? $this->normalizeCost(Math::unitCost($balance->unit_cost)),
                'Set a cost price on the item base UOM before issuing stock under standard costing.',
                'STOCK_STANDARD_COST_REQUIRED',
            ),
            // The last known cost (kept on the balance after it reaches zero), else the item's cost price.
            default => $this->lastKnownCost($item, $balance),
        };

        $consumedValue = Math::value($qty, $unitCost);
        if (
            $method === InventoryCostingMethod::MovingAverage
            && bccomp($newQty, '0', Math::QTY_SCALE) === 0
            && bccomp($oldValue, '0', Math::MONEY_SCALE) > 0
        ) {
            $consumedValue = $oldValue;
            $unitCost = Math::unitCostFromValue($qty, $consumedValue);
        }
        $newValue = bcsub($oldValue, $consumedValue, Math::MONEY_SCALE);
        if (bccomp($newQty, '0', Math::QTY_SCALE) === 0) {
            $newValue = Math::money(0);
        }
        if (bccomp($newQty, '0', Math::QTY_SCALE) > 0 && bccomp($newValue, '0', Math::MONEY_SCALE) < 0) {
            $newValue = Math::money(0);
        }
        $this->writeBalanceValue($balance, $newQty, $newValue, $unitCost);

        return [
            'unit_cost' => $unitCost,
            'value' => $consumedValue,
        ];
    }

    /**
     * @return array{correction: string, quantity: string} the cost correction and quantity that filled negative stock
     */
    private function applyInbound(
        InventoryCostingMethod $method,
        Item $item,
        StockBalance $balance,
        string $qty,
        string $unitCost,
        string $valueDelta,
        int $movementId,
    ): array {
        $oldQty = Math::qty($balance->quantity);
        $oldValue = Math::money($balance->inventory_value);
        $newQty = bcadd($oldQty, $qty, Math::QTY_SCALE);
        $noFill = ['correction' => Math::money(0), 'quantity' => Math::qty(0)];

        if (bccomp($oldQty, '0', Math::QTY_SCALE) < 0) {
            // Stock below zero was sold at the last known cost. The part of this inbound that fills it is
            // valued at the new cost; the difference is a cost correction (more or less cost of sales).
            $short = Math::absQty($oldQty);
            $fill = bccomp($qty, $short, Math::QTY_SCALE) < 0 ? $qty : $short;
            $issued = bccomp($oldValue, '0', Math::MONEY_SCALE) < 0
                ? bcdiv(bcmul(bcmul($oldValue, '-1', Math::MONEY_SCALE), $fill, 12), $short, 12)
                : bcmul($fill, Math::unitCost($balance->unit_cost), 12);
            $correction = Math::money(bcsub(bcmul($fill, $unitCost, 12), $issued, 12));
            $newValue = bcsub(bcadd($oldValue, $valueDelta, Math::MONEY_SCALE), $correction, Math::MONEY_SCALE);

            if ($method->usesLayers() && bccomp($newQty, '0', Math::QTY_SCALE) > 0) {
                InventoryCostLayer::query()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $balance->warehouse_id,
                    'lot_id' => $balance->lot_id,
                    'source_movement_id' => $movementId,
                    'quantity_remaining' => $newQty,
                    'unit_cost' => $unitCost,
                ]);
            }

            // Still below zero: the rest keeps the cost it was issued at.
            $this->writeBalanceValue(
                $balance,
                $newQty,
                $newValue,
                bccomp($newQty, '0', Math::QTY_SCALE) < 0 ? Math::unitCost($balance->unit_cost) : $unitCost,
            );

            return ['correction' => $correction, 'quantity' => $fill];
        }

        if (bccomp($oldQty, '0', Math::QTY_SCALE) === 0) {
            if ($method->usesLayers()) {
                InventoryCostLayer::query()->create([
                    'item_id' => $item->id,
                    'warehouse_id' => $balance->warehouse_id,
                    'lot_id' => $balance->lot_id,
                    'source_movement_id' => $movementId,
                    'quantity_remaining' => $qty,
                    'unit_cost' => $unitCost,
                ]);
            }
            $this->writeBalanceValue($balance, $qty, $valueDelta, $unitCost);

            return $noFill;
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

            return $noFill;
        }

        if ($method === InventoryCostingMethod::MovingAverage) {
            $newValue = bcadd($oldValue, $valueDelta, Math::MONEY_SCALE);
            $this->writeBalanceValue($balance, $newQty, $newValue);

            return $noFill;
        }

        $standard = $unitCost;
        $newValue = Math::value($newQty, $standard);
        $this->writeBalanceValue($balance, $newQty, $newValue);

        return $noFill;
    }

    /**
     * Take `$qty` from the balance's layers (oldest first). `$belowZero` of it may have no layer (stock going
     * negative): that part is costed at the last known cost.
     *
     * @return array{value: string, last_cost: string}
     */
    private function consumeLayers(
        Item $item,
        StockBalance $balance,
        string $qty,
        ?string $preferredUnitCost,
        string $belowZero,
    ): array {
        $warehouseId = (int) $balance->warehouse_id;
        $lotId = $balance->lot_id !== null ? (int) $balance->lot_id : null;
        $remaining = $qty;
        $consumedValue = Math::money(0);
        $lastCost = null;

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
            $preferred = Math::unitCost($preferredUnitCost);
            $layers = $layers->sortBy(function (InventoryCostLayer $layer) use ($preferred): int {
                return bccomp(Math::unitCost($layer->unit_cost), $preferred, Math::UNIT_COST_SCALE) === 0 ? 0 : 1;
            })->values();
        }

        foreach ($layers as $layer) {
            if (bccomp($remaining, '0', Math::QTY_SCALE) <= 0) {
                break;
            }

            $layerQty = Math::qty($layer->quantity_remaining);
            $take = bccomp($layerQty, $remaining, Math::QTY_SCALE) <= 0 ? $layerQty : $remaining;
            $consumedValue = bcadd($consumedValue, Math::value($take, Math::unitCost($layer->unit_cost)), Math::MONEY_SCALE);
            $lastCost = Math::unitCost($layer->unit_cost);
            $left = bcsub($layerQty, $take, Math::QTY_SCALE);
            $layer->update(['quantity_remaining' => $left]);
            $remaining = bcsub($remaining, $take, Math::QTY_SCALE);
        }

        $lastCost ??= $this->lastKnownCost($item, $balance);
        if (bccomp($remaining, '0', Math::QTY_SCALE) > 0) {
            if (bccomp($remaining, Math::qty($belowZero), Math::QTY_SCALE) > 0) {
                abort(422, 'Insufficient cost layers for this issue. Recost or adjust opening stock.', [
                    'X-Error-Code' => 'STOCK_COST_LAYER_INSUFFICIENT',
                ]);
            }
            $consumedValue = bcadd($consumedValue, Math::value($remaining, $lastCost), Math::MONEY_SCALE);
        }

        return ['value' => $consumedValue, 'last_cost' => $lastCost];
    }

    /**
     * Cost of stock issued with nothing left to take it from: the balance's last cost (kept after it reaches
     * zero), else the newest layer's, else the item's cost price, else zero.
     */
    private function lastKnownCost(Item $item, StockBalance $balance): string
    {
        $cost = Math::unitCost($balance->unit_cost);
        if (bccomp($cost, '0', Math::UNIT_COST_SCALE) > 0) {
            return $cost;
        }
        $layer = InventoryCostLayer::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $balance->warehouse_id)
            ->orderByDesc('id')
            ->value('unit_cost');
        if ($layer !== null && bccomp(Math::unitCost($layer), '0', Math::UNIT_COST_SCALE) > 0) {
            return Math::unitCost($layer);
        }

        return $this->standardUnitCost($item) ?? Math::unitCost(0);
    }

    private function writeBalanceValue(
        StockBalance $balance,
        string $qty,
        string $value,
        ?string $fallbackUnitCost = null,
    ): void {
        if (bccomp($qty, '0', Math::QTY_SCALE) < 0) {
            // Below zero the value is what the missing quantity was issued at (never above zero).
            $value = bccomp($value, '0', Math::MONEY_SCALE) > 0 ? Math::money(0) : Math::money($value);
            $unitCost = bccomp($value, '0', Math::MONEY_SCALE) < 0
                ? Math::unitCostFromValue(Math::absQty($qty), bcmul($value, '-1', Math::MONEY_SCALE))
                : ($fallbackUnitCost ?? Math::unitCost($balance->unit_cost));
        } elseif (bccomp($qty, '0', Math::QTY_SCALE) === 0) {
            // Keep the last cost: stock issued while at zero is costed at it.
            $value = Math::money(0);
            $unitCost = $fallbackUnitCost ?? Math::unitCost($balance->unit_cost);
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

        $cost = Math::unitCost($value);
        if (bccomp($cost, '0', Math::UNIT_COST_SCALE) < 0) {
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
