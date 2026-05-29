<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Brand\Models\Brand;
use App\Modules\Category\Models\Category;
use App\Modules\Category\Support\CategoryTree;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Support\Collection;

readonly class ItemResponseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $sku,
        public ?string $pluCode,
        public ?array $itemType,
        public ?array $category,
        public ?array $brand,
        public ?array $baseUom,
        public ?int $vatGroupId,
        public ?array $vatGroup,
        public ?string $description,
        public bool $trackInventory,
        public bool $allowSale,
        public bool $allowPurchase,
        public bool $isActive,
        public ?array $primaryImage,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Item $item): self
    {
        $item->loadMissing(['itemType', 'category', 'brand', 'baseUom', 'vatGroup', 'primaryImageAttachment']);

        return new self(
            id: $item->id,
            name: $item->name,
            sku: $item->sku,
            pluCode: $item->plu_code,
            itemType: self::itemTypeBrief($item->itemType),
            category: self::categoryBrief($item->category),
            brand: self::brandBrief($item->brand),
            baseUom: self::uomBrief($item->baseUom),
            vatGroupId: $item->vat_group_id !== null ? (int) $item->vat_group_id : null,
            vatGroup: self::vatGroupBrief($item->vatGroup),
            description: $item->description,
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
        return $items
            ->map(fn (Item $item): array => self::fromModel($item)->toArray())
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
            'plu_code' => $this->pluCode,
            'item_type' => $this->itemType,
            'category' => $this->category,
            'brand' => $this->brand,
            'base_uom' => $this->baseUom,
            'vat_group_id' => $this->vatGroupId,
            'vat_group' => $this->vatGroup,
            'description' => $this->description,
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
     * @return array{id: int, code: string, name: string}|null
     */
    private static function categoryBrief(?Category $category): ?array
    {
        if (! $category) {
            return null;
        }

        static $allCategories = null;
        $allCategories ??= Category::query()->get(['id', 'name', 'parent_id']);

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'path_label' => CategoryTree::pathLabel($category, $allCategories),
        ];
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
