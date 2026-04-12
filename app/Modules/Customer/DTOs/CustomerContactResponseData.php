<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Models\CustomerContact;
use Illuminate\Support\Collection;

readonly class CustomerContactResponseData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $position,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(CustomerContact $contact): self
    {
        return new self(
            id: $contact->id,
            customerId: $contact->customer_id,
            name: $contact->name,
            phone: $contact->phone,
            email: $contact->email,
            position: $contact->position,
            createdAt: (string) $contact->created_at,
            updatedAt: (string) $contact->updated_at,
        );
    }

    /**
     * @param  Collection<int, CustomerContact>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (CustomerContact $c): array => self::fromModel($c)->toArray())
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
            'customer_id' => $this->customerId,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'position' => $this->position,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
