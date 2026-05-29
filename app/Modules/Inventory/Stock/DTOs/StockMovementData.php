<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\DTOs;

use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Http\Requests\StoreStockAdjustmentRequest;

readonly class StockMovementData
{
    public function __construct(
        public int $itemId,
        public int $warehouseId,
        public string $quantityDelta,
        public StockMovementType $type,
        public ?string $referenceType,
        public ?int $referenceId,
        public ?int $itemUomId,
        public ?string $notes,
        public ?int $userId,
    ) {}

    public static function forTransfer(
        int $itemId,
        int $warehouseId,
        string $quantityDelta,
        StockMovementType $type,
        int $stockTransferId,
        ?int $itemUomId,
        ?string $notes,
        ?int $userId,
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
        );
    }

    public static function fromAdjustmentRequest(StoreStockAdjustmentRequest $request, ?int $userId): self
    {
        $data = $request->validated();

        return new self(
            itemId: (int) $data['item_id'],
            warehouseId: (int) $data['warehouse_id'],
            quantityDelta: self::formatDelta($data['quantity_delta']),
            type: StockMovementType::Adjustment,
            referenceType: null,
            referenceId: null,
            itemUomId: isset($data['item_uom_id']) ? (int) $data['item_uom_id'] : null,
            notes: self::normalizeNotes($data['notes'] ?? null),
            userId: $userId,
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
}
