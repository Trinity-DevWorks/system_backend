<?php

namespace App\Modules\Category\DTOs;

use App\Modules\Category\Http\Requests\StoreCategoryRequest;
use App\Modules\Category\Http\Requests\UpdateCategoryRequest;
use App\Modules\Category\Models\Category;

readonly class CategoryData
{
    public function __construct(
        public ?int $parentId,
        public string $code,
        public string $name,
        public string $color,
        public ?string $description,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreCategoryRequest $request): self
    {
        $data = $request->validated();

        return new self(
            parentId: self::normalizeParentId($data['parent_id'] ?? null),
            code: $data['code'],
            name: $data['name'],
            color: $data['color'],
            description: self::normalizeDescription($data['description'] ?? null),
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    public static function fromUpdateRequest(UpdateCategoryRequest $request, Category $category): self
    {
        $data = $request->validated();

        return new self(
            parentId: array_key_exists('parent_id', $data)
                ? self::normalizeParentId($data['parent_id'])
                : $category->parent_id,
            code: $data['code'] ?? $category->code,
            name: $data['name'] ?? $category->name,
            color: $data['color'] ?? $category->color,
            description: array_key_exists('description', $data)
                ? self::normalizeDescription($data['description'])
                : $category->description,
            isActive: array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $category->is_active,
        );
    }

    /**
     * @return array{parent_id: ?int, code: string, name: string, color: string, description: ?string, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'parent_id' => $this->parentId,
            'code' => $this->code,
            'name' => $this->name,
            'color' => $this->color,
            'description' => $this->description,
            'is_active' => $this->isActive,
        ];
    }

    private static function normalizeParentId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private static function normalizeDescription(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
