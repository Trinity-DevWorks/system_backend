<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\DTOs;

use App\Modules\PaymentTerm\Http\Requests\StorePaymentTermRequest;
use App\Modules\PaymentTerm\Http\Requests\UpdatePaymentTermRequest;

readonly class PaymentTermData
{
    public function __construct(
        public string $code,
        public string $name,
        public int $dueDays,
        public ?string $description,
        public bool $isDefault,
        public bool $isActive,
    ) {}

    public static function fromStoreRequest(StorePaymentTermRequest $request): self
    {
        return self::fromValidatedArray($request->validated());
    }

    public static function fromUpdateRequest(UpdatePaymentTermRequest $request): self
    {
        return self::fromValidatedArray($request->validated());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromValidatedArray(array $data): self
    {
        return new self(
            code: trim((string) $data['code']),
            name: trim((string) $data['name']),
            dueDays: (int) $data['due_days'],
            description: array_key_exists('description', $data) && $data['description'] !== null && trim((string) $data['description']) !== ''
                ? trim((string) $data['description'])
                : null,
            isDefault: (bool) $data['is_default'],
            isActive: (bool) $data['is_active'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'due_days' => $this->dueDays,
            'description' => $this->description,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
        ];
    }
}
