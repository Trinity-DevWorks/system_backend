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
        public ?string $itemCode,
        public ?string $pluCode,
        public int $itemTypeId,
        public int $categoryId,
        public ?int $brandId,
        public int $unitGroupId,
        public ?int $vatGroupId,
        public ?string $description,
        public ?string $ticketName,
        public ?string $kitchenName,
        public bool $sendToKitchen,
        public bool $qrEnabled,
        public ?string $qrDescription,
        public ?string $posName,
        public ?string $color,
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
        $posDefaults = ItemTypeDefaults::posFlagsForCode($type->code);

        $allowSale = array_key_exists('allow_sale', $data)
            ? (bool) $data['allow_sale']
            : $defaults['allow_sale'];

        $pos = self::resolvePosFields($data, $posDefaults, $allowSale);

        return new self(
            name: $data['name'],
            sku: self::normalizeSku($data['sku']),
            itemCode: self::normalizeItemCode($data['item_code'] ?? null),
            pluCode: self::normalizePluCode($data['plu_code'] ?? null),
            itemTypeId: (int) $data['item_type_id'],
            categoryId: (int) $data['category_id'],
            brandId: isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            unitGroupId: (int) $data['unit_group_id'],
            vatGroupId: isset($data['vat_group_id']) ? (int) $data['vat_group_id'] : null,
            description: self::normalizeOptionalText($data['description'] ?? null),
            ticketName: $pos['ticket_name'],
            kitchenName: $pos['kitchen_name'],
            sendToKitchen: $pos['send_to_kitchen'],
            qrEnabled: $pos['qr_enabled'],
            qrDescription: $pos['qr_description'],
            posName: $pos['pos_name'],
            color: $pos['color'],
            trackInventory: array_key_exists('track_inventory', $data)
                ? (bool) $data['track_inventory']
                : $defaults['track_inventory'],
            allowSale: $allowSale,
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
        $posDefaults = ItemTypeDefaults::posFlagsForCode($type->code);

        $allowSale = array_key_exists('allow_sale', $data)
            ? (bool) $data['allow_sale']
            : (bool) $item->allow_sale;

        $posInput = self::mergePosInputForUpdate($data, $item);
        $pos = self::resolvePosFields($posInput, $posDefaults, $allowSale);

        return new self(
            name: $data['name'] ?? $item->name,
            sku: array_key_exists('sku', $data) ? self::normalizeSku($data['sku']) : $item->sku,
            itemCode: array_key_exists('item_code', $data)
                ? self::normalizeItemCode($data['item_code'])
                : $item->item_code,
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
            unitGroupId: array_key_exists('unit_group_id', $data)
                ? (int) $data['unit_group_id']
                : (int) $item->unit_group_id,
            vatGroupId: array_key_exists('vat_group_id', $data)
                ? ($data['vat_group_id'] === null ? null : (int) $data['vat_group_id'])
                : $item->vat_group_id,
            description: array_key_exists('description', $data)
                ? self::normalizeOptionalText($data['description'])
                : $item->description,
            ticketName: $pos['ticket_name'],
            kitchenName: $pos['kitchen_name'],
            sendToKitchen: $pos['send_to_kitchen'],
            qrEnabled: $pos['qr_enabled'],
            qrDescription: $pos['qr_description'],
            posName: $pos['pos_name'],
            color: $pos['color'],
            trackInventory: array_key_exists('track_inventory', $data)
                ? (bool) $data['track_inventory']
                : (bool) $item->track_inventory,
            allowSale: $allowSale,
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
            'item_code' => $this->itemCode,
            'plu_code' => $this->pluCode,
            'item_type_id' => $this->itemTypeId,
            'category_id' => $this->categoryId,
            'brand_id' => $this->brandId,
            'unit_group_id' => $this->unitGroupId,
            'vat_group_id' => $this->vatGroupId,
            'description' => $this->description,
            'ticket_name' => $this->ticketName,
            'kitchen_name' => $this->kitchenName,
            'send_to_kitchen' => $this->sendToKitchen,
            'qr_enabled' => $this->qrEnabled,
            'qr_description' => $this->qrDescription,
            'pos_name' => $this->posName,
            'color' => $this->color,
            'track_inventory' => $this->trackInventory,
            'allow_sale' => $this->allowSale,
            'allow_purchase' => $this->allowPurchase,
            'is_active' => $this->isActive,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{send_to_kitchen: bool, qr_enabled: bool}  $posDefaults
     * @return array{
     *     ticket_name: ?string,
     *     kitchen_name: ?string,
     *     send_to_kitchen: bool,
     *     qr_enabled: bool,
     *     qr_description: ?string,
     *     pos_name: ?string,
     *     color: ?string
     * }
     */
    private static function resolvePosFields(array $data, array $posDefaults, bool $allowSale): array
    {
        if (! $allowSale) {
            return [
                'ticket_name' => null,
                'kitchen_name' => null,
                'send_to_kitchen' => false,
                'qr_enabled' => false,
                'qr_description' => null,
                'pos_name' => null,
                'color' => null,
            ];
        }

        $qrEnabled = array_key_exists('qr_enabled', $data)
            ? (bool) $data['qr_enabled']
            : $posDefaults['qr_enabled'];

        $qrDescription = $qrEnabled
            ? self::normalizeOptionalText($data['qr_description'] ?? null)
            : null;

        return [
            'ticket_name' => self::normalizeOptionalText($data['ticket_name'] ?? null),
            'kitchen_name' => self::normalizeOptionalText($data['kitchen_name'] ?? null),
            'send_to_kitchen' => array_key_exists('send_to_kitchen', $data)
                ? (bool) $data['send_to_kitchen']
                : $posDefaults['send_to_kitchen'],
            'qr_enabled' => $qrEnabled,
            'qr_description' => $qrDescription,
            'pos_name' => self::normalizeOptionalText($data['pos_name'] ?? null),
            'color' => self::normalizeOptionalColor($data['color'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function mergePosInputForUpdate(array $data, Item $item): array
    {
        return [
            'ticket_name' => array_key_exists('ticket_name', $data) ? $data['ticket_name'] : $item->ticket_name,
            'kitchen_name' => array_key_exists('kitchen_name', $data) ? $data['kitchen_name'] : $item->kitchen_name,
            'send_to_kitchen' => array_key_exists('send_to_kitchen', $data) ? $data['send_to_kitchen'] : $item->send_to_kitchen,
            'qr_enabled' => array_key_exists('qr_enabled', $data) ? $data['qr_enabled'] : $item->qr_enabled,
            'qr_description' => array_key_exists('qr_description', $data) ? $data['qr_description'] : $item->qr_description,
            'pos_name' => array_key_exists('pos_name', $data) ? $data['pos_name'] : $item->pos_name,
            'color' => array_key_exists('color', $data) ? $data['color'] : $item->color,
        ];
    }

    private static function normalizeSku(string $value): string
    {
        return strtoupper(trim($value));
    }

    private static function normalizeItemCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizePluCode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeOptionalText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeOptionalColor(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));

        return $normalized === '' ? null : $normalized;
    }
}
