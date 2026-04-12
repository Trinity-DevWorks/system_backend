<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Http\Requests\StoreCustomerGroupRequest;
use App\Modules\Customer\Http\Requests\UpdateCustomerGroupRequest;

readonly class CustomerGroupData
{
    public function __construct(
        public string $name,
    ) {}

    public static function fromStoreRequest(StoreCustomerGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(name: $d['name']);
    }

    public static function fromUpdateRequest(UpdateCustomerGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(name: $d['name']);
    }

    /**
     * @return array{name: string}
     */
    public function toArray(): array
    {
        return ['name' => $this->name];
    }
}
