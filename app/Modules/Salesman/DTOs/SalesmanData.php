<?php

declare(strict_types=1);

namespace App\Modules\Salesman\DTOs;

use App\Modules\Salesman\Enums\CommissionType;
use App\Modules\Salesman\Http\Requests\StoreSalesmanRequest;
use App\Modules\Salesman\Http\Requests\UpdateSalesmanRequest;
use Illuminate\Support\Carbon;

readonly class SalesmanData
{
    public function __construct(
        public ?string $salesmanCode,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
        public ?string $email,
        public ?string $address,
        public CommissionType $commissionType,
        public ?string $commissionValue,
        public ?string $targetAmount,
        public ?Carbon $hireDate,
        public ?int $warehouseId,
        public ?string $userId,
        public bool $isActive,
        public ?string $notes,
    ) {}

    public static function fromStoreRequest(StoreSalesmanRequest $request): self
    {
        $data = $request->validated();

        return self::fromValidatedArray($data);
    }

    public static function fromUpdateRequest(UpdateSalesmanRequest $request): self
    {
        $data = $request->validated();

        return self::fromValidatedArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromValidatedArray(array $data): self
    {
        $hire = $data['hire_date'] ?? null;

        $salesmanCode = $data['salesman_code'] ?? null;

        return new self(
            salesmanCode: $salesmanCode !== null && $salesmanCode !== '' ? (string) $salesmanCode : null,
            firstName: trim((string) $data['first_name']),
            lastName: trim((string) $data['last_name']),
            phone: isset($data['phone']) ? (string) $data['phone'] : null,
            email: isset($data['email']) ? (string) $data['email'] : null,
            address: isset($data['address']) ? (string) $data['address'] : null,
            commissionType: CommissionType::from((string) $data['commission_type']),
            commissionValue: isset($data['commission_value']) ? (string) $data['commission_value'] : null,
            targetAmount: isset($data['target_amount']) ? (string) $data['target_amount'] : null,
            hireDate: $hire !== null && $hire !== '' ? Carbon::parse((string) $hire) : null,
            warehouseId: isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null,
            userId: isset($data['user_id']) ? (string) $data['user_id'] : null,
            isActive: (bool) $data['is_active'],
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $commissionValue = $this->commissionType === CommissionType::None
            ? null
            : $this->commissionValue;

        return [
            'salesman_code' => $this->salesmanCode,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'full_name' => self::buildFullName($this->firstName, $this->lastName),
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'commission_type' => $this->commissionType->value,
            'commission_value' => $commissionValue,
            'target_amount' => $this->targetAmount,
            'hire_date' => $this->hireDate?->toDateString(),
            'warehouse_id' => $this->warehouseId,
            'user_id' => $this->userId,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
        ];
    }

    public static function buildFullName(string $firstName, string $lastName): string
    {
        return trim($firstName.' '.$lastName);
    }
}
