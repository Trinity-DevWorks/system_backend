<?php

declare(strict_types=1);

namespace App\Modules\Salesman\DTOs;

use App\Modules\Salesman\Enums\CommissionType;
use App\Modules\Salesman\Models\Salesman;
use Illuminate\Support\Collection;

readonly class SalesmanResponseData
{
    public function __construct(
        public string $id,
        public ?string $salesmanCode,
        public string $firstName,
        public string $lastName,
        public string $fullName,
        public ?string $phone,
        public ?string $email,
        public ?string $address,
        public string $commissionType,
        public ?string $commissionValue,
        public ?string $targetAmount,
        public ?string $hireDate,
        public ?int $warehouseId,
        public ?string $warehouseName,
        public ?string $userId,
        public ?string $userName,
        public bool $isActive,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Salesman $salesman): self
    {
        $commissionType = $salesman->commission_type instanceof CommissionType
            ? $salesman->commission_type
            : CommissionType::from((string) $salesman->commission_type);

        $warehouse = $salesman->relationLoaded('warehouse') ? $salesman->warehouse : null;
        $user = $salesman->relationLoaded('user') ? $salesman->user : null;

        return new self(
            id: $salesman->id,
            salesmanCode: $salesman->salesman_code,
            firstName: (string) $salesman->first_name,
            lastName: (string) $salesman->last_name,
            fullName: (string) $salesman->full_name,
            phone: $salesman->phone,
            email: $salesman->email,
            address: $salesman->address,
            commissionType: $commissionType->value,
            commissionValue: $salesman->commission_value !== null ? (string) $salesman->commission_value : null,
            targetAmount: $salesman->target_amount !== null ? (string) $salesman->target_amount : null,
            hireDate: $salesman->hire_date?->toDateString(),
            warehouseId: $salesman->warehouse_id,
            warehouseName: $warehouse?->name,
            userId: $salesman->user_id,
            userName: $user?->name,
            isActive: (bool) $salesman->is_active,
            notes: $salesman->notes,
            createdAt: (string) $salesman->created_at,
            updatedAt: (string) $salesman->updated_at,
        );
    }

    /**
     * @param  Collection<int, Salesman>  $salesmen
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $salesmen): array
    {
        return $salesmen
            ->map(fn (Salesman $salesman): array => self::fromModel($salesman)->toArray())
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
            'salesman_code' => $this->salesmanCode,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => $this->fullName,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'commission_type' => $this->commissionType,
            'commission_value' => $this->commissionValue,
            'target_amount' => $this->targetAmount,
            'hire_date' => $this->hireDate,
            'warehouse_id' => $this->warehouseId,
            'warehouse_name' => $this->warehouseName,
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
