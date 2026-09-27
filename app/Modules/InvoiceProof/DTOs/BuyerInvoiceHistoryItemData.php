<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

readonly class BuyerInvoiceHistoryItemData
{
    public function __construct(
        public string $id,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public string $grandTotal,
        public ?string $currencyCode,
        public string $status,
        public int $exp,
        public string $sig,
    ) {}

    /**
     * @return array{
     *     id: string,
     *     invoice_number: ?string,
     *     invoice_date: ?string,
     *     grand_total: string,
     *     currency_code: ?string,
     *     status: string,
     *     exp: int,
     *     sig: string
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => $this->invoiceDate,
            'grand_total' => $this->grandTotal,
            'currency_code' => $this->currencyCode,
            'status' => $this->status,
            'exp' => $this->exp,
            'sig' => $this->sig,
        ];
    }
}
