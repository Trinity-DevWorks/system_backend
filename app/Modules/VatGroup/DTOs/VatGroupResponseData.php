<?php

namespace App\Modules\VatGroup\DTOs;

use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Support\Collection;

readonly class VatGroupResponseData
{
    public function __construct(
        public int $id,
        public string $abrv,
        public string $name,
        public string $percentage,
        public bool $isDefault,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(VatGroup $vatGroup): self
    {
        return new self(
            id: $vatGroup->id,
            abrv: $vatGroup->abrv,
            name: $vatGroup->name,
            percentage: (string) $vatGroup->percentage,
            isDefault: (bool) $vatGroup->is_default,
            createdAt: (string) $vatGroup->created_at,
            updatedAt: (string) $vatGroup->updated_at,
        );
    }

    /**
     * @param  Collection<int, VatGroup>  $vatGroups
     * @return array<int, array{id:int,abrv:string,name:string,percentage:string,is_default:bool,created_at:string,updated_at:string}>
     */
    public static function collectionToArray(Collection $vatGroups): array
    {
        return $vatGroups
            ->map(fn (VatGroup $vatGroup): array => self::fromModel($vatGroup)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,abrv:string,name:string,percentage:string,is_default:bool,created_at:string,updated_at:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'abrv' => $this->abrv,
            'name' => $this->name,
            'percentage' => $this->percentage,
            'is_default' => $this->isDefault,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
