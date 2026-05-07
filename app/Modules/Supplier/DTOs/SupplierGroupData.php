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
    ) {}

    public static function fromStoreRequest(StoreSupplierGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name']
        );
    }

    public static function fromUpdateRequest(UpdateSupplierGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name']
        );
    }

    /**
     * @return array{code: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
        ];
    }
}
