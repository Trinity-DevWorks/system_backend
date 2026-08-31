<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockCount;

readonly class StockMovementData
{
    public function __construct(
        public string $itemId,
        public int $warehouseId,
        public string $quantityDelta,
        public StockMovementType $type,
        public ?string $referenceType,
        public ?string $referenceId,
        public ?int $itemUomId,
        public ?string $notes,
        public ?string $userId,
        public ?string $unitCost = null,
        public ?int $lotId = null,
    ) {}

    public static function forTransfer(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        StockMovementType $type,
        string $stockTransferId,
        ?int $itemUomId,
        ?string $notes,
        ?string $userId,
        ?string $unitCost = null,
        ?int $lotId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: $type,
            referenceType: 'stock_transfer',
            referenceId: $stockTransferId,
            itemUomId: $itemUomId,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: self::normalizeUnitCost($unitCost),
            lotId: $lotId,
        );
    }

    public static function forOpening(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        ?string $unitCost,
        ?int $itemUomId,
        ?string $notes,
        ?string $userId,
        ?int $lotId = null,
        ?string $openingStockId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: StockMovementType::Opening,
            referenceType: $openingStockId !== null ? OpeningStock::REFERENCE_TYPE : null,
            referenceId: $openingStockId,
            itemUomId: $itemUomId,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: self::normalizeUnitCost($unitCost),
            lotId: $lotId,
        );
    }

    public static function forPurchase(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        ?string $purchaseOrderId,
        ?string $unitCost,
        ?int $itemUomId,
        ?string $notes,
        ?string $userId,
        ?int $lotId = null,
        ?string $goodsReceiptId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: StockMovementType::Purchase,
            referenceType: $goodsReceiptId !== null ? 'goods_receipt' : 'purchase_order',
            referenceId: $goodsReceiptId ?? $purchaseOrderId,
            itemUomId: $itemUomId,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: self::normalizeUnitCost($unitCost),
            lotId: $lotId,
        );
    }

    public static function forProduction(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        StockMovementType $type,
        ?string $unitCost,
        ?int $itemUomId,
        ?string $notes,
        ?string $userId,
        ?int $lotId = null,
        ?string $productionId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: $type,
            referenceType: $productionId !== null ? Production::REFERENCE_TYPE : null,
            referenceId: $productionId,
            itemUomId: $itemUomId,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: self::normalizeUnitCost($unitCost),
            lotId: $lotId,
        );
    }

    public static function forBundleSale(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        ?string $notes,
        ?string $userId,
        ?int $lotId = null,
        ?string $bundleExplosionId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: StockMovementType::BundleSale,
            referenceType: $bundleExplosionId !== null ? BundleExplosion::REFERENCE_TYPE : null,
            referenceId: $bundleExplosionId,
            itemUomId: null,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: null,
            lotId: $lotId,
        );
    }

    public static function forCount(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        ?string $unitCost,
        ?string $notes,
        ?string $userId,
        ?int $lotId = null,
        ?string $stockCountId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: StockMovementType::Count,
            referenceType: $stockCountId !== null ? StockCount::REFERENCE_TYPE : null,
            referenceId: $stockCountId,
            itemUomId: null,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: self::normalizeUnitCost($unitCost),
            lotId: $lotId,
        );
    }

    public static function forAdjustment(
        string $itemId,
        int $warehouseId,
        string $quantityDelta,
        ?string $unitCost,
        ?int $itemUomId,
        ?string $notes,
        ?string $userId,
        ?int $lotId = null,
        ?string $stockAdjustmentId = null,
    ): self {
        return new self(
            itemId: $itemId,
            warehouseId: $warehouseId,
            quantityDelta: self::formatDelta($quantityDelta),
            type: StockMovementType::Adjustment,
            referenceType: $stockAdjustmentId !== null ? StockAdjustment::REFERENCE_TYPE : null,
            referenceId: $stockAdjustmentId,
            itemUomId: $itemUomId,
            notes: self::normalizeNotes($notes),
            userId: $userId,
            unitCost: self::normalizeUnitCost($unitCost),
            lotId: $lotId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'warehouse_id' => $this->warehouseId,
            'lot_id' => $this->lotId,
            'quantity_delta' => $this->quantityDelta,
            'type' => $this->type->value,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'item_uom_id' => $this->itemUomId,
            'notes' => $this->notes,
            'user_id' => $this->userId,
        ];
    }

    private static function formatDelta(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private static function normalizeNotes(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeUnitCost(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $cost = PriceMath::normalize($value);
        if (bccomp($cost, '0', PriceMath::scale()) < 0) {
            abort(422, 'Unit cost cannot be negative.', ['X-Error-Code' => 'STOCK_UNIT_COST_INVALID']);
        }

        return $cost;
    }
}
