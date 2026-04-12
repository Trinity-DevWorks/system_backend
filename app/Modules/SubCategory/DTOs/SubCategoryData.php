<?php

namespace App\Modules\SubCategory\DTOs;

use App\Modules\SubCategory\Http\Requests\StoreSubCategoryRequest;
use App\Modules\SubCategory\Http\Requests\UpdateSubCategoryRequest;

readonly class SubCategoryData
{
    public function __construct(
        public int $categoryId,
        public string $name,
        public string $color,
    ) {}

    public static function fromStoreRequest(StoreSubCategoryRequest $request): self
    {
        $data = $request->validated();

        return new self(
            categoryId: (int) $data['category_id'],
            name: $data['name'],
            color: $data['color'],
        );
    }

    public static function fromUpdateRequest(UpdateSubCategoryRequest $request): self
    {
        $data = $request->validated();

        return new self(
            categoryId: (int) $data['category_id'],
            name: $data['name'],
            color: $data['color'],
        );
    }

    /**
     * @return array{category_id: int, name: string, color: string}
     */
    public function toArray(): array
    {
        return [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
