<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\SupplierGroup;
use Illuminate\Support\Collection;

readonly class SupplierGroupResponseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(SupplierGroup $group): self
    {
        return new self(
            id: $group->id,
            name: $group->name,
            createdAt: (string) $group->created_at,
            updatedAt: (string) $group->updated_at,
        );
    }

    /**
     * @param  Collection<int, SupplierGroup>  $groups
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $groups): array
    {
        return $groups
            ->map(fn (SupplierGroup $g): array => self::fromModel($g)->toArray())
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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
