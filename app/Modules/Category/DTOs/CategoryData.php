<?php

namespace App\Modules\Category\DTOs;

use App\Modules\Category\Http\Requests\StoreCategoryRequest;
use App\Modules\Category\Http\Requests\UpdateCategoryRequest;

readonly class CategoryData
{
    public function __construct(
        public string $name,
        public string $color,
    ) {}

    public static function fromStoreRequest(StoreCategoryRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: $data['name'],
            color: $data['color'],
        );
    }

    public static function fromUpdateRequest(UpdateCategoryRequest $request): self
    {
        $data = $request->validated();

        return new self(
            name: $data['name'],
            color: $data['color'],
        );
    }

    /**
     * @return array{name: string, color: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'color' => $this->color,
        ];
    }
}
