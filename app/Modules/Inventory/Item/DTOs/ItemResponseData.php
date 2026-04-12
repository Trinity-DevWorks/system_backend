<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Support\Collection;

readonly class ItemResponseData
{
    /**
     * @param  array<string, mixed>|null  $baseUom
     * @param  array<string, mixed>|null  $purchaseUom
     * @param  array<string, mixed>|null  $salesUom
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $type,
        public ?array $baseUom,
        public ?array $purchaseUom,
        public ?array $salesUom,
        public bool $active,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Item $item): self
    {
        $item->loadMissing(['baseUom', 'purchaseUom', 'salesUom']);

        return new self(
            id: $item->id,
            code: $item->code,
            name: $item->name,
            type: $item->type,
            baseUom: self::uomBrief($item->baseUom),
            purchaseUom: self::uomBrief($item->purchaseUom),
            salesUom: self::uomBrief($item->salesUom),
            active: (bool) $item->active,
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
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'base_uom' => $this->baseUom,
            'purchase_uom' => $this->purchaseUom,
            'sales_uom' => $this->salesUom,
            'active' => $this->active,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
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
}
