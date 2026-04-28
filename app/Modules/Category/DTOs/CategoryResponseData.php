<?php

namespace App\Modules\Category\DTOs;

use App\Modules\Category\Models\Category;
use Illuminate\Support\Collection;

readonly class CategoryResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $color,
        public ?string $description,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Category $category): self
    {
        return new self(
            id: $category->id,
            code: $category->code,
            name: $category->name,
            color: $category->color,
            description: $category->description,
            isActive: (bool) $category->is_active,
            createdAt: (string) $category->created_at,
            updatedAt: (string) $category->updated_at,
        );
    }

    /**
     * @param  Collection<int, Category>  $categories
     * @return array<int, array{id:int,code:string,name:string,color:string,description:?string,is_active:bool,created_at:string,updated_at:string}>
     */
    public static function collectionToArray(Collection $categories): array
    {
        return $categories
            ->map(fn (Category $category): array => self::fromModel($category)->toArray())
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,code:string,name:string,color:string,description:?string,is_active:bool,created_at:string,updated_at:string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'color' => $this->color,
            'description' => $this->description,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
