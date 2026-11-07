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
        public ?int $priceListId = null,
        public bool $priceListProvided = false,
    ) {}

    public static function fromStoreRequest(StoreCustomerGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name'],
            isActive: (bool) ($d['is_active'] ?? true),
            priceListId: isset($d['price_list_id']) ? (int) $d['price_list_id'] : null,
            priceListProvided: array_key_exists('price_list_id', $d),
        );
    }

    public static function fromUpdateRequest(UpdateCustomerGroupRequest $request): self
    {
        $d = $request->validated();

        return new self(
            code: $d['code'],
            name: $d['name'],
            isActive: (bool) $d['is_active'],
            priceListId: isset($d['price_list_id']) ? (int) $d['price_list_id'] : null,
            priceListProvided: array_key_exists('price_list_id', $d),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->isActive,
        ];
        if ($this->priceListProvided) {
            $out['price_list_id'] = $this->priceListId;
        }

        return $out;
    }
}
