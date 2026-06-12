<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Brand\Models\Brand;
use App\Modules\Category\Models\Category;
use App\Modules\Category\Support\CategoryTree;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Support\Collection;

readonly class ItemResponseData
{
    public function __construct(
        public string $id,
        public string $name,
        public string $sku,
        public ?string $itemCode,
        public ?string $pluCode,
        public ?array $itemType,
        public ?array $category,
        public ?array $brand,
        public int $unitGroupId,
        public ?array $unitGroup,
        public ?int $baseUomId,
        public ?array $baseUom,
        public ?int $vatGroupId,
        public ?array $vatGroup,
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
        public ?array $primaryImage,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Item $item, ?Collection $categoryLookup = null): self
    {
        $item->loadMissing(['itemType', 'category', 'brand', 'unitGroup', 'baseUom', 'vatGroup', 'primaryImageAttachment']);

        $categoryLookup ??= self::categoryLookupForIds(
            $item->category_id !== null ? [(int) $item->category_id] : [],
        );

        return new self(
            id: $item->id,
            name: $item->name,
            sku: $item->sku,
            itemCode: $item->item_code,
            pluCode: $item->plu_code,
            itemType: self::itemTypeBrief($item->itemType),
            category: self::categoryBrief($item->category, $categoryLookup),
            brand: self::brandBrief($item->brand),
            unitGroupId: (int) $item->unit_group_id,
            unitGroup: self::unitGroupBrief($item->unitGroup),
            baseUomId: $item->base_uom_id !== null ? (int) $item->base_uom_id : null,
            baseUom: self::uomBrief($item->baseUom),
            vatGroupId: $item->vat_group_id !== null ? (int) $item->vat_group_id : null,
            vatGroup: self::vatGroupBrief($item->vatGroup),
            description: $item->description,
            ticketName: $item->ticket_name,
            kitchenName: $item->kitchen_name,
            sendToKitchen: (bool) $item->send_to_kitchen,
            qrEnabled: (bool) $item->qr_enabled,
            qrDescription: $item->qr_description,
            posName: $item->pos_name,
            color: $item->color,
            trackInventory: (bool) $item->track_inventory,
            allowSale: (bool) $item->allow_sale,
            allowPurchase: (bool) $item->allow_purchase,
            isActive: (bool) $item->is_active,
            primaryImage: self::primaryImageBrief($item),
            createdAt: (string) $item->created_at,
            updatedAt: (string) $item->updated_at,
        );
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $items): array
    {
        if ($items->isNotEmpty()) {
            $items->loadMissing(['itemType', 'category', 'brand', 'unitGroup', 'baseUom', 'vatGroup', 'primaryImageAttachment']);
        }

        $categoryLookup = self::categoryLookupForItems($items);

        return $items
            ->map(fn (Item $item): array => self::fromModel($item, $categoryLookup)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'item_code' => $this->itemCode,
            'plu_code' => $this->pluCode,
            'item_type' => $this->itemType,
            'category' => $this->category,
            'brand' => $this->brand,
            'unit_group_id' => $this->unitGroupId,
            'unit_group' => $this->unitGroup,
            'base_uom_id' => $this->baseUomId,
            'base_uom' => $this->baseUom,
            'vat_group_id' => $this->vatGroupId,
            'vat_group' => $this->vatGroup,
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
            'primary_image' => $this->primaryImage,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * @return array{id: int, file_name: string, mime_type: string}|null
     */
    private static function primaryImageBrief(Item $item): ?array
    {
        $attachment = $item->primaryImageAttachment;
        if (! $attachment) {
            return null;
        }

        return [
            'id' => $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private static function itemTypeBrief(?ItemType $itemType): ?array
    {
        if (! $itemType) {
            return null;
        }

        return [
            'id' => $itemType->id,
            'code' => $itemType->code,
            'name' => $itemType->name,
        ];
    }

    /**
     * @param  Collection<int, Category>  $categoryLookup
     * @return array{id: int, code: string, name: string, path_label: string}|null
     */
    private static function categoryBrief(?Category $category, Collection $categoryLookup): ?array
    {
        if (! $category) {
            return null;
        }

        $node = $categoryLookup->firstWhere('id', $category->id) ?? $category;

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'path_label' => CategoryTree::pathLabel($node, $categoryLookup),
        ];
    }

    /**
     * @param  Collection<int, Item>  $items
     * @return Collection<int, Category>
     */
    private static function categoryLookupForItems(Collection $items): Collection
    {
        return self::categoryLookupForIds($items->pluck('category_id'));
    }

    /**
     * Load only categories referenced by items plus ancestors needed for path labels.
     *
     * @param  iterable<int|null>  $categoryIds
     * @return Collection<int, Category>
     */
    private static function categoryLookupForIds(iterable $categoryIds): Collection
    {
        $ids = collect($categoryIds)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        /** @var Collection<int, Category> $byId */
        $byId = Category::query()
            ->whereIn('id', $ids)
            ->get(['id', 'code', 'name', 'parent_id'])
            ->keyBy('id');

        $pendingParentIds = $byId
            ->pluck('parent_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->diff($byId->keys())
            ->values();

        while ($pendingParentIds->isNotEmpty()) {
            $parents = Category::query()
                ->whereIn('id', $pendingParentIds)
                ->get(['id', 'code', 'name', 'parent_id']);

            foreach ($parents as $parent) {
                $byId->put($parent->id, $parent);
            }

            $pendingParentIds = $parents
                ->pluck('parent_id')
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->diff($byId->keys())
                ->values();
        }

        return $byId->values();
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private static function brandBrief(?Brand $brand): ?array
    {
        if (! $brand) {
            return null;
        }

        return [
            'id' => $brand->id,
            'code' => $brand->code,
            'name' => $brand->name,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string}|null
     */
    private static function unitGroupBrief(?UnitGroup $unitGroup): ?array
    {
        if (! $unitGroup) {
            return null;
        }

        return [
            'id' => $unitGroup->id,
            'code' => $unitGroup->code,
            'name' => $unitGroup->name,
        ];
    }

    /**
     * @return array{id:int,code:string,name:string,unit_group_id:int}|null
     */
    private static function uomBrief(?UnitOfMeasurement $uom): ?array
    {
        if (! $uom) {
            return null;
        }

        return [
            'id' => $uom->id,
            'code' => $uom->code,
            'name' => $uom->name,
            'unit_group_id' => $uom->unit_group_id,
        ];
    }

    /**
     * @return array{id:int,abrv:string,name:string,percentage:string}|null
     */
    private static function vatGroupBrief(?VatGroup $vatGroup): ?array
    {
        if (! $vatGroup) {
            return null;
        }

        return [
            'id' => $vatGroup->id,
            'abrv' => $vatGroup->abrv,
            'name' => $vatGroup->name,
            'percentage' => (string) $vatGroup->percentage,
        ];
    }
}
