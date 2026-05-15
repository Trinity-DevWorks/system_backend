<?php

declare(strict_types=1);

namespace App\Modules\PaymentMethod\DTOs;

use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use Illuminate\Support\Collection;

readonly class PaymentMethodResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public string $type,
        public ?int $currencyId,
        public ?string $currencyCode,
        public ?string $currencyName,
        public bool $requiresReference,
        public bool $supportsChange,
        public bool $isDefault,
        public bool $isActive,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(PaymentMethod $paymentMethod): self
    {
        $type = $paymentMethod->type instanceof PaymentMethodType
            ? $paymentMethod->type
            : PaymentMethodType::from((string) $paymentMethod->type);

        $currency = $paymentMethod->relationLoaded('currency') ? $paymentMethod->currency : null;

        return new self(
            id: $paymentMethod->id,
            code: (string) $paymentMethod->code,
            name: (string) $paymentMethod->name,
            type: $type->value,
            currencyId: $paymentMethod->currency_id,
            currencyCode: $currency?->code,
            currencyName: $currency?->name,
            requiresReference: (bool) $paymentMethod->requires_reference,
            supportsChange: (bool) $paymentMethod->supports_change,
            isDefault: (bool) $paymentMethod->is_default,
            isActive: (bool) $paymentMethod->is_active,
            notes: $paymentMethod->notes,
            createdAt: (string) $paymentMethod->created_at,
            updatedAt: (string) $paymentMethod->updated_at,
        );
    }

    /**
     * @param  Collection<int, PaymentMethod>  $paymentMethods
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $paymentMethods): array
    {
        return $paymentMethods
            ->map(fn (PaymentMethod $paymentMethod): array => self::fromModel($paymentMethod)->toArray())
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
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type,
            'currency_id' => $this->currencyId,
            'currency_code' => $this->currencyCode,
            'currency_name' => $this->currencyName,
            'requires_reference' => $this->requiresReference,
            'supports_change' => $this->supportsChange,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
