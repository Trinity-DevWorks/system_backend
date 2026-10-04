<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Supplier\Http\Requests\StoreSupplierItemRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierItemRequest;
use App\Modules\Supplier\Models\SupplierItem;

readonly class SupplierItemData
{
    public function __construct(
        public string $itemId,
        public ?string $supplierItemCode,
        public ?string $lastPurchasePrice,
        public int $leadTimeDays,
        public bool $isPreferred,
    ) {}

    public static function fromStoreRequest(StoreSupplierItemRequest $request): self
    {
        $data = $request->validated();

        return new self(
            itemId: $data['item_id'],
            supplierItemCode: self::normalizeItemCode($data['supplier_item_code'] ?? null),
            lastPurchasePrice: self::normalizePrice($data['last_purchase_price'] ?? null),
            leadTimeDays: max(0, (int) ($data['lead_time_days'] ?? 0)),
            isPreferred: (bool) ($data['is_preferred'] ?? false),
        );
    }

    public static function fromUpdateRequest(UpdateSupplierItemRequest $request, SupplierItem $row): self
    {
        $data = $request->validated();

        return new self(
            itemId: (string) $row->item_id,
            supplierItemCode: array_key_exists('supplier_item_code', $data)
                ? self::normalizeItemCode($data['supplier_item_code'])
                : $row->supplier_item_code,
            lastPurchasePrice: array_key_exists('last_purchase_price', $data)
                ? self::normalizePrice($data['last_purchase_price'])
                : ($row->last_purchase_price !== null ? (string) $row->last_purchase_price : null),
            leadTimeDays: array_key_exists('lead_time_days', $data)
                ? max(0, (int) $data['lead_time_days'])
                : (int) $row->lead_time_days,
            isPreferred: array_key_exists('is_preferred', $data)
                ? (bool) $data['is_preferred']
                : (bool) $row->is_preferred,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'item_id' => $this->itemId,
            'supplier_item_code' => $this->supplierItemCode,
            'last_purchase_price' => $this->lastPurchasePrice,
            'lead_time_days' => $this->leadTimeDays,
            'is_preferred' => $this->isPreferred,
        ];
    }

    private static function normalizeItemCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizePrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return PriceMath::normalize($value);
    }
}
