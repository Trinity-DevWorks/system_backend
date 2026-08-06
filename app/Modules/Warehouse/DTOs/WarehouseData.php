<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\DTOs;

use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Http\Requests\StoreWarehouseRequest;
use App\Modules\Warehouse\Http\Requests\UpdateWarehouseRequest;

readonly class WarehouseData
{
    public function __construct(
        public string $name,
        public string $shortcutName,
        public WarehouseType $type,
        public ?int $branchId,
        public ?string $address,
        public ?string $description,
        public ?string $managerId,
        public bool $isActive,
        public bool $isDefault,
        public bool $isDefaultSales,
        public bool $isDefaultProduction,
        public bool $isDefaultPurchase,
        public bool $isDefaultStorage,
    ) {}

    public static function fromStoreRequest(StoreWarehouseRequest $request): self
    {
        return self::fromValidated($request->validated());
    }

    public static function fromUpdateRequest(UpdateWarehouseRequest $request): self
    {
        return self::fromValidated($request->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromValidated(array $data): self
    {
        $type = WarehouseType::from((string) $data['type']);
        $branchId = isset($data['branch_id']) && $data['branch_id'] !== '' && $data['branch_id'] !== null
            ? (int) $data['branch_id']
            : null;

        if (! $type->requiresBranch()) {
            $branchId = null;
        }

        return new self(
            name: $data['name'],
            shortcutName: $data['shortcut_name'],
            type: $type,
            branchId: $branchId,
            address: self::nullableString($data['address'] ?? null),
            description: self::nullableString($data['description'] ?? null),
            managerId: isset($data['manager_id']) && $data['manager_id'] !== ''
                ? (string) $data['manager_id']
                : null,
            isActive: (bool) $data['is_active'],
            isDefault: (bool) $data['is_default'],
            isDefaultSales: (bool) $data['is_default_sales'],
            isDefaultProduction: (bool) $data['is_default_production'],
            isDefaultPurchase: (bool) $data['is_default_purchase'],
            isDefaultStorage: (bool) $data['is_default_storage'],
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<string, bool>
     */
    public function defaultFlags(): array
    {
        return [
            'is_default' => $this->isDefault,
            'is_default_sales' => $this->isDefaultSales,
            'is_default_production' => $this->isDefaultProduction,
            'is_default_purchase' => $this->isDefaultPurchase,
            'is_default_storage' => $this->isDefaultStorage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'shortcut_name' => $this->shortcutName,
            'type' => $this->type->value,
            'branch_id' => $this->branchId,
            'address' => $this->address,
            'description' => $this->description,
            'manager_id' => $this->managerId,
            'is_active' => $this->isActive,
            'is_default' => $this->isDefault,
            'is_default_sales' => $this->isDefaultSales,
            'is_default_production' => $this->isDefaultProduction,
            'is_default_purchase' => $this->isDefaultPurchase,
            'is_default_storage' => $this->isDefaultStorage,
        ];
    }
}
