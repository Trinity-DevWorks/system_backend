<?php

declare(strict_types=1);

namespace App\Modules\Customer\DTOs;

use App\Modules\Customer\Models\CustomerLedgerEntry;

readonly class CustomerLedgerEntryResponseData
{
    public function __construct(
        public int $id,
        public int $customerId,
        public int $currencyId,
        public ?string $currencyCode,
        public string $debit,
        public string $credit,
        public string $referenceType,
        public ?int $referenceId,
        public string $transactionDate,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(CustomerLedgerEntry $entry): self
    {
        $entry->loadMissing('currency:id,code');

        return new self(
            id: $entry->id,
            customerId: $entry->customer_id,
            currencyId: (int) $entry->currency_id,
            currencyCode: $entry->currency?->code,
            debit: (string) $entry->debit,
            credit: (string) $entry->credit,
            referenceType: $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type,
            referenceId: $entry->reference_id !== null ? (int) $entry->reference_id : null,
            transactionDate: $entry->transaction_date->toDateString(),
            createdAt: (string) $entry->created_at,
            updatedAt: (string) $entry->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'currency_id' => $this->currencyId,
            'currency_code' => $this->currencyCode,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'transaction_date' => $this->transactionDate,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
