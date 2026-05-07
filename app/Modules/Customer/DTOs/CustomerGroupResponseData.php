<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Models\CustomerGroup;
use Illuminate\Support\Collection;

readonly class CustomerGroupResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(CustomerGroup $group): self
    {
        return new self(
            id: $group->id,
            code: $group->code,
            name: $group->name,
            createdAt: (string) $group->created_at,
            updatedAt: (string) $group->updated_at,
        );
    }

    /**
     * @param  Collection<int, CustomerGroup>  $groups
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $groups): array
    {
        return $groups
            ->map(fn (CustomerGroup $g): array => self::fromModel($g)->toArray())
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
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
