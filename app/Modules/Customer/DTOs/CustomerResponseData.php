<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Enums\CustomerType;
use App\Modules\Customer\Models\Customer;
use Illuminate\Support\Collection;

readonly class CustomerResponseData
{
    /**
     * @param  array<string, mixed>|null  $customerGroup
     */
    public function __construct(
        public int $id,
        public string $customerCode,
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $type,
        public ?int $customerGroupId,
        public ?array $customerGroup,
        public string $creditLimit,
        public string $openingBalance,
        public bool $isActive,
        public bool $isVatRegistered,
        public ?string $vatNumber,
        public ?string $notes,
        public string $balance,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deletedAt,
    ) {}

    public static function fromModel(Customer $customer, string $balance): self
    {
        $customer->loadMissing('customerGroup');

        $group = $customer->customerGroup;

        return new self(
            id: $customer->id,
            customerCode: (string) $customer->customer_code,
            name: $customer->name,
            email: $customer->email,
            phone: $customer->phone,
            type: $customer->type instanceof CustomerType ? $customer->type->value : (string) $customer->type,
            customerGroupId: $customer->customer_group_id,
            customerGroup: $group ? ['id' => $group->id, 'name' => $group->name] : null,
            creditLimit: (string) $customer->credit_limit,
            openingBalance: (string) $customer->opening_balance,
            isActive: (bool) $customer->is_active,
            isVatRegistered: (bool) $customer->is_vat_registered,
            vatNumber: $customer->vat_number,
            notes: $customer->notes,
            balance: $balance,
            createdAt: (string) $customer->created_at,
            updatedAt: (string) $customer->updated_at,
            deletedAt: $customer->deleted_at?->toIso8601String(),
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     * @param  array<int, string>  $balancesByCustomerId
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $customers, array $balancesByCustomerId): array
    {
        return $customers
            ->map(function (Customer $c) use ($balancesByCustomerId): array {
                $bal = $balancesByCustomerId[$c->id] ?? '0.0000';

                return self::fromModel($c, $bal)->toArray();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_code' => $this->customerCode,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'type' => $this->type,
            'customer_group_id' => $this->customerGroupId,
            'customer_group' => $this->customerGroup,
            'credit_limit' => $this->creditLimit,
            'opening_balance' => $this->openingBalance,
            'is_active' => $this->isActive,
            'is_vat_registered' => $this->isVatRegistered,
            'vat_number' => $this->vatNumber,
            'notes' => $this->notes,
            'balance' => $this->balance,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'deleted_at' => $this->deletedAt,
        ];
    }
}
