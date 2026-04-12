<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\SupplierContact;
use Illuminate\Support\Collection;

readonly class SupplierContactResponseData
{
    public function __construct(
        public int $id,
        public int $supplierId,
        public string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $position,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(SupplierContact $contact): self
    {
        return new self(
            id: $contact->id,
            supplierId: $contact->supplier_id,
            name: $contact->name,
            phone: $contact->phone,
            email: $contact->email,
            position: $contact->position,
            createdAt: (string) $contact->created_at,
            updatedAt: (string) $contact->updated_at,
        );
    }

    /**
     * @param  Collection<int, SupplierContact>  $rows
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows): array
    {
        return $rows
            ->map(fn (SupplierContact $c): array => self::fromModel($c)->toArray())
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
            'supplier_id' => $this->supplierId,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'position' => $this->position,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
