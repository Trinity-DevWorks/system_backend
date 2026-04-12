<?php

namespace App\Modules\VatGroup\DTOs;

use App\Modules\VatGroup\Http\Requests\StoreVatGroupRequest;
use App\Modules\VatGroup\Http\Requests\UpdateVatGroupRequest;

readonly class VatGroupData
{
    public function __construct(
        public string $abrv,
        public string $name,
        public float $percentage,
        public bool $isDefault,
    ) {}

    public static function fromStoreRequest(StoreVatGroupRequest $request): self
    {
        $data = $request->validated();

        return new self(
            abrv: $data['abrv'],
            name: $data['name'],
            percentage: (float) $data['percentage'],
            isDefault: (bool) $data['is_default'],
        );
    }

    public static function fromUpdateRequest(UpdateVatGroupRequest $request): self
    {
        $data = $request->validated();

        return new self(
            abrv: $data['abrv'],
            name: $data['name'],
            percentage: (float) $data['percentage'],
            isDefault: (bool) $data['is_default'],
        );
    }

    /**
     * @return array{abrv:string,name:string,percentage:float,is_default:bool}
     */
    public function toArray(): array
    {
        return [
            'abrv' => $this->abrv,
            'name' => $this->name,
            'percentage' => $this->percentage,
            'is_default' => $this->isDefault,
        ];
    }
}
