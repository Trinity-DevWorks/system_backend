<?php

namespace App\Modules\Inventory\Item\DTOs;

use App\Modules\Inventory\Item\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemRequest;

readonly class ItemData
{
    public function __construct(
        public string $code,
        public string $name,
        public string $type,
        public ?int $baseUomId,
        public ?int $purchaseUomId,
        public ?int $salesUomId,
        public bool $active,
    ) {}

    public static function fromStoreRequest(StoreItemRequest $request): self
    {
        $data = $request->validated();

        return new self(
            code: $data['code'],
            name: $data['name'],
            type: $data['type'],
            baseUomId: isset($data['base_uom_id']) ? (int) $data['base_uom_id'] : null,
            purchaseUomId: isset($data['purchase_uom_id']) ? (int) $data['purchase_uom_id'] : null,
            salesUomId: isset($data['sales_uom_id']) ? (int) $data['sales_uom_id'] : null,
            active: (bool) $data['active'],
        );
    }

    public static function fromUpdateRequest(UpdateItemRequest $request): self
    {
        $data = $request->validated();

        return new self(
            code: $data['code'],
            name: $data['name'],
            type: $data['type'],
            baseUomId: isset($data['base_uom_id']) ? (int) $data['base_uom_id'] : null,
            purchaseUomId: isset($data['purchase_uom_id']) ? (int) $data['purchase_uom_id'] : null,
            salesUomId: isset($data['sales_uom_id']) ? (int) $data['sales_uom_id'] : null,
            active: (bool) $data['active'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'base_uom_id' => $this->baseUomId,
            'purchase_uom_id' => $this->purchaseUomId,
            'sales_uom_id' => $this->salesUomId,
            'active' => $this->active,
        ];
    }
}
