<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Http\Requests\StoreSupplierGroupRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierGroupRequest;

readonly class SupplierGroupData
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreSupplierGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name'],
            isActive: (bool) ($d['is_active'] ?? true),
        );
    }

    public static function fromUpdateRequest(UpdateSupplierGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name'],
            isActive: (bool) $d['is_active'],
        );
    }

    /**
     * @return array{code: string, name: string, is_active: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->isActive,
        ];
    }
}
