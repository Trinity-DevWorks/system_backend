<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;

readonly class CustomerTableRowResponseData
{
    /**
     * @param  array{id: int, name: string}|null  $customerGroup
     * @param  array{id: int, full_name: string, salesman_code: string|null}|null  $salesman
     */
    public function __construct(
        public int $id,
        public string $customerCode,
        public string $name,
        public ?int $customerGroupId,
        public ?array $customerGroup,
        public ?int $salesmanId,
        public ?array $salesman,
        public ?string $phone,
        public ?string $email,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Customer $customer): self
    {
        $customer->loadMissing(['customerGroup:id,name', 'salesman:id,full_name,salesman_code']);

        $group = $customer->customerGroup;
        $sm = $customer->salesman;

        $status = $customer->status instanceof CustomerStatus
            ? $customer->status->value
            : (string) ($customer->status ?? CustomerStatus::Active->value);

        return new self(
            id: $customer->id,
            customerCode: (string) ($customer->customer_code ?? ''),
            name: $customer->name,
            customerGroupId: $customer->customer_group_id,
            customerGroup: $group ? ['id' => $group->id, 'name' => $group->name] : null,
            salesmanId: $customer->salesman_id,
            salesman: $sm ? [
                'id' => $sm->id,
                'full_name' => $sm->full_name,
                'salesman_code' => $sm->salesman_code,
            ] : null,
            phone: $customer->phone,
            email: $customer->email,
            status: $status,
            createdAt: (string) $customer->created_at,
            updatedAt: (string) $customer->updated_at,
        );
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
            'customer_group_id' => $this->customerGroupId,
            'customer_group' => $this->customerGroup,
            'salesman_id' => $this->salesmanId,
            'salesman' => $this->salesman,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
