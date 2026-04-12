<?php

declare(strict_types=1);

namespace App\Modules\Currency\DTOs;

use App\Modules\Currency\Models\Currency;
use Illuminate\Support\Collection;

readonly class CurrencyResponseData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public string $isoCode,
        public ?string $symbol,
        public ?string $smallestUnit,
        public ?string $roundLimit,
        public ?string $acceptableAmountOverdue,
        public ?string $allowedDifferenceInReceipt,
        public ?string $allowedDifferenceInPayment,
        public bool $active,
        public bool $isPrimary,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Currency $currency): self
    {
        return new self(
            id: $currency->id,
            name: $currency->name,
            code: $currency->code,
            isoCode: $currency->iso_code,
            symbol: $currency->symbol,
            smallestUnit: $currency->smallest_unit !== null ? (string) $currency->smallest_unit : null,
            roundLimit: $currency->round_limit !== null ? (string) $currency->round_limit : null,
            acceptableAmountOverdue: $currency->acceptable_amount_overdue !== null ? (string) $currency->acceptable_amount_overdue : null,
            allowedDifferenceInReceipt: $currency->allowed_difference_in_receipt !== null ? (string) $currency->allowed_difference_in_receipt : null,
            allowedDifferenceInPayment: $currency->allowed_difference_in_payment !== null ? (string) $currency->allowed_difference_in_payment : null,
            active: (bool) $currency->active,
            isPrimary: $currency->isPrimary(),
            createdAt: (string) $currency->created_at,
            updatedAt: (string) $currency->updated_at,
        );
    }

    /**
     * @param  Collection<int, Currency>  $currencies
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $currencies): array
    {
        return $currencies
            ->map(fn (Currency $c): array => self::fromModel($c)->toArray())
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
            'name' => $this->name,
            'code' => $this->code,
            'iso_code' => $this->isoCode,
            'symbol' => $this->symbol,
            'smallest_unit' => $this->smallestUnit,
            'round_limit' => $this->roundLimit,
            'acceptable_amount_overdue' => $this->acceptableAmountOverdue,
            'allowed_difference_in_receipt' => $this->allowedDifferenceInReceipt,
            'allowed_difference_in_payment' => $this->allowedDifferenceInPayment,
            'active' => $this->active,
            'is_primary' => $this->isPrimary,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
