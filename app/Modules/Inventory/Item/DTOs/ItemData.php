<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\ItemTypeDefaults;
use App\Modules\Inventory\ItemType\Models\ItemType;

readonly class ItemData
{
    public function __construct(
        public string $name,
        public string $sku,
        public ?string $pluCode,
        public int $itemTypeId,
        public int $categoryId,
        public ?int $brandId,
        public int $baseUomId,
        public ?int $vatGroupId,
        public ?string $description,
        public bool $trackInventory,
        public bool $allowSale,
        public bool $allowPurchase,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreItemRequest $request): self
    {
        $data = $request->validated();
        $type = ItemType::query()->findOrFail((int) $data['item_type_id']);
        $defaults = ItemTypeDefaults::flagsForCode($type->code);

        return new self(
            name: $data['name'],
            sku: self::normalizeSku($data['sku']),
            pluCode: self::normalizePluCode($data['plu_code'] ?? null),
            itemTypeId: (int) $data['item_type_id'],
            categoryId: (int) $data['category_id'],
            brandId: isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            baseUomId: (int) $data['base_uom_id'],
            vatGroupId: isset($data['vat_group_id']) ? (int) $data['vat_group_id'] : null,
            description: self::normalizeDescription($data['description'] ?? null),
            trackInventory: array_key_exists('track_inventory', $data)
                ? (bool) $data['track_inventory']
                : $defaults['track_inventory'],
            allowSale: array_key_exists('allow_sale', $data)
                ? (bool) $data['allow_sale']
                : $defaults['allow_sale'],
            allowPurchase: array_key_exists('allow_purchase', $data)
                ? (bool) $data['allow_purchase']
                : $defaults['allow_purchase'],
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    public static function fromUpdateRequest(UpdateItemRequest $request, Item $item): self
    {
        $data = $request->validated();
        $itemTypeId = array_key_exists('item_type_id', $data)
            ? (int) $data['item_type_id']
            : (int) $item->item_type_id;
        $type = ItemType::query()->findOrFail($itemTypeId);
        $defaults = ItemTypeDefaults::flagsForCode($type->code);

        return new self(
            name: $data['name'] ?? $item->name,
            sku: array_key_exists('sku', $data) ? self::normalizeSku($data['sku']) : $item->sku,
            pluCode: array_key_exists('plu_code', $data)
                ? self::normalizePluCode($data['plu_code'])
                : $item->plu_code,
            itemTypeId: $itemTypeId,
            categoryId: array_key_exists('category_id', $data)
                ? (int) $data['category_id']
                : (int) $item->category_id,
            brandId: array_key_exists('brand_id', $data)
                ? ($data['brand_id'] === null ? null : (int) $data['brand_id'])
                : $item->brand_id,
            baseUomId: array_key_exists('base_uom_id', $data)
                ? (int) $data['base_uom_id']
                : (int) $item->base_uom_id,
            vatGroupId: array_key_exists('vat_group_id', $data)
                ? ($data['vat_group_id'] === null ? null : (int) $data['vat_group_id'])
                : $item->vat_group_id,
            description: array_key_exists('description', $data)
                ? self::normalizeDescription($data['description'])
                : $item->description,
            trackInventory: array_key_exists('track_inventory', $data)
                ? (bool) $data['track_inventory']
                : (bool) $item->track_inventory,
            allowSale: array_key_exists('allow_sale', $data)
                ? (bool) $data['allow_sale']
                : (bool) $item->allow_sale,
            allowPurchase: array_key_exists('allow_purchase', $data)
                ? (bool) $data['allow_purchase']
                : (bool) $item->allow_purchase,
            isActive: array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $item->is_active,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'sku' => $this->sku,
            'plu_code' => $this->pluCode,
            'item_type_id' => $this->itemTypeId,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'base_uom_id' => $this->baseUomId,
            'vat_group_id' => $this->vatGroupId,
            'description' => $this->description,
            'track_inventory' => $this->trackInventory,
            'allow_sale' => $this->allowSale,
            'allow_purchase' => $this->allowPurchase,
            'is_active' => $this->isActive,
        ];
    }

    private static function normalizeSku(string $value): string
    {
        return strtoupper(trim($value));
    }

    private static function normalizePluCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeDescription(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
