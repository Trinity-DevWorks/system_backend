<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Http\Requests\StoreCustomerGroupRequest;
use App\Modules\Customer\Http\Requests\UpdateCustomerGroupRequest;

readonly class CustomerGroupData
{
    public function __construct(
        public string $code,
        public string $name,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StoreCustomerGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name'],
            isActive: (bool) ($d['is_active'] ?? true),
        );
    }

    public static function fromUpdateRequest(UpdateCustomerGroupRequest $request): self
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
