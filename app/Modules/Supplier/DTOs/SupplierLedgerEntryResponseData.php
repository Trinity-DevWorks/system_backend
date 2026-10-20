<?php

declare(strict_types=1);

namespace App\Modules\Supplier\DTOs;

use App\Modules\Supplier\Models\SupplierLedgerEntry;

readonly class SupplierLedgerEntryResponseData
{
    public function __construct(
        public int $id,
        public string $supplierId,
        public int $currencyId,
        public ?string $currencyCode,
        public ?string $currencySymbol,
        public string $debit,
        public string $credit,
        public string $referenceType,
        public ?string $referenceId,
        public ?string $documentNumber,
        public ?string $runningBalance,
        public bool $reversed,
        public string $transactionDate,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(
        SupplierLedgerEntry $entry,
        ?string $documentNumber = null,
        ?string $runningBalance = null,
        bool $reversed = false,
    ): self {
        $entry->loadMissing('currency:id,code,symbol');

        return new self(
            id: $entry->id,
            supplierId: (string) $entry->supplier_id,
            currencyId: (int) $entry->currency_id,
            currencyCode: $entry->currency?->code,
            currencySymbol: $entry->currency?->symbol,
            debit: (string) $entry->debit,
            credit: (string) $entry->credit,
            referenceType: $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type,
            referenceId: $entry->reference_id !== null ? (string) $entry->reference_id : null,
            documentNumber: $documentNumber,
            runningBalance: $runningBalance,
            reversed: $reversed,
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
            'supplier_id' => $this->supplierId,
            'currency_id' => $this->currencyId,
            'currency_code' => $this->currencyCode,
            'currency_symbol' => $this->currencySymbol,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'reference_type' => $this->referenceType,
            'reference_id' => $this->referenceId,
            'document_number' => $this->documentNumber,
            'running_balance' => $this->runningBalance,
            'reversed' => $this->reversed,
            'transaction_date' => $this->transactionDate,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
