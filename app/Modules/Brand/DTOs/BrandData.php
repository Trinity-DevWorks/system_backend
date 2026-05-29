<?php

namespace App\Modules\Brand\DTOs;

use App\Modules\Brand\Http\Requests\StoreBrandRequest;
use App\Modules\Brand\Http\Requests\UpdateBrandRequest;
use App\Modules\Brand\Models\Brand;

readonly class BrandData
{
    public function __construct(
        public string $code,
        public string $name,
        public ?int $parentBrandId,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreBrandRequest $request): self
    {
        $data = $request->validated();

        return new self(
            code: $data['code'],
            name: $data['name'],
            parentBrandId: self::normalizeParentBrandId($data['parent_brand_id'] ?? null),
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    public static function fromUpdateRequest(UpdateBrandRequest $request, Brand $brand): self
    {
        $data = $request->validated();

        return new self(
            code: $data['code'] ?? $brand->code,
            name: $data['name'] ?? $brand->name,
            parentBrandId: array_key_exists('parent_brand_id', $data)
                ? self::normalizeParentBrandId($data['parent_brand_id'])
                : $brand->parent_brand_id,
            isActive: array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $brand->is_active,
        );
    }

    /**
     * @return array{code: string, name: string, parent_brand_id: ?int, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'parent_brand_id' => $this->parentBrandId,
            'is_active' => $this->isActive,
        ];
    }

    private static function normalizeParentBrandId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
