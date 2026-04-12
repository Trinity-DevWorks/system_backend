<?php

declare(strict_types=1);

namespace App\Modules\Currency\DTOs;

use App\Modules\Currency\Http\Requests\StoreCurrencyRequest;

readonly class CurrencyData
{
    public function __construct(
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
        public ?float $rate,
        public ?int $fromCurrencyId,
        public ?int $toCurrencyId,
    ) {}

    public static function fromStoreRequest(StoreCurrencyRequest $request): self
    {
        $d = $request->validated();

        return new self(
            name: $d['name'],
            code: $d['code'],
            isoCode: $d['iso_code'],
            symbol: $d['symbol'] ?? null,
            smallestUnit: isset($d['smallest_unit']) ? (string) $d['smallest_unit'] : null,
            roundLimit: isset($d['round_limit']) ? (string) $d['round_limit'] : null,
            acceptableAmountOverdue: isset($d['acceptable_amount_overdue']) ? (string) $d['acceptable_amount_overdue'] : null,
            allowedDifferenceInReceipt: isset($d['allowed_difference_in_receipt']) ? (string) $d['allowed_difference_in_receipt'] : null,
            allowedDifferenceInPayment: isset($d['allowed_difference_in_payment']) ? (string) $d['allowed_difference_in_payment'] : null,
            active: $d['active'] ?? true,
            isPrimary: $d['is_primary'] ?? false,
            rate: isset($d['rate']) && is_numeric($d['rate']) ? (float) $d['rate'] : null,
            fromCurrencyId: isset($d['from_currency_id']) ? (int) $d['from_currency_id'] : null,
            toCurrencyId: isset($d['to_currency_id']) ? (int) $d['to_currency_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toModelArray(): array
    {
        return [
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
        ];
    }
}
