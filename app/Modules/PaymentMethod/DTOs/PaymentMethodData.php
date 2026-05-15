<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\DTOs;

use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Http\Requests\StorePaymentMethodRequest;
use App\Modules\PaymentMethod\Http\Requests\UpdatePaymentMethodRequest;

readonly class PaymentMethodData
{
    public function __construct(
        public string $code,
        public string $name,
        public PaymentMethodType $type,
        public ?int $currencyId,
        public bool $requiresReference,
        public bool $supportsChange,
        public bool $isDefault,
        public bool $isActive,
        public ?string $notes,
    ) {}

    public static function fromStoreRequest(StorePaymentMethodRequest $request): self
    {
        $data = $request->validated();

        return self::fromValidatedArray($data);
    }

    public static function fromUpdateRequest(UpdatePaymentMethodRequest $request): self
    {
        $data = $request->validated();

        return self::fromValidatedArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function fromValidatedArray(array $data): self
    {
        return new self(
            code: trim((string) $data['code']),
            name: trim((string) $data['name']),
            type: PaymentMethodType::from((string) $data['type']),
            currencyId: isset($data['currency_id']) ? (int) $data['currency_id'] : null,
            requiresReference: (bool) $data['requires_reference'],
            supportsChange: (bool) $data['supports_change'],
            isDefault: (bool) $data['is_default'],
            isActive: (bool) $data['is_active'],
            notes: isset($data['notes']) ? (string) $data['notes'] : null,
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
            'type' => $this->type->value,
            'currency_id' => $this->currencyId,
            'requires_reference' => $this->requiresReference,
            'supports_change' => $this->supportsChange,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
        ];
    }
}
