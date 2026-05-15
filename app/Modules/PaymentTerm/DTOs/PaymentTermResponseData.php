<?php

declare(strict_types=1);

namespace App\Modules\PaymentTerm\DTOs;

use App\Modules\PaymentTerm\Models\PaymentTerm;
use Illuminate\Support\Collection;

readonly class PaymentTermResponseData
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public int $dueDays,
        public ?string $description,
        public bool $isDefault,
        public bool $isActive,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(PaymentTerm $paymentTerm): self
    {
        return new self(
            id: $paymentTerm->id,
            code: (string) $paymentTerm->code,
            name: (string) $paymentTerm->name,
            dueDays: (int) $paymentTerm->due_days,
            description: $paymentTerm->description,
            isDefault: (bool) $paymentTerm->is_default,
            isActive: (bool) $paymentTerm->is_active,
            createdAt: (string) $paymentTerm->created_at,
            updatedAt: (string) $paymentTerm->updated_at,
        );
    }

    /**
     * @param  Collection<int, PaymentTerm>  $paymentTerms
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $paymentTerms): array
    {
        return $paymentTerms
            ->map(fn (PaymentTerm $paymentTerm): array => self::fromModel($paymentTerm)->toArray())
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
            'due_days' => $this->dueDays,
            'description' => $this->description,
            'is_default' => $this->isDefault,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
