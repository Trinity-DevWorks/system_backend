<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Support\CanonicalInvoiceFormatter;

/**
 * One hashed invoice line. Item text, warehouse, lot, and notes are the values
 * copied at posting.
 */
readonly class CanonicalInvoiceLineData
{
    /**
     * @param  array{name: ?string, shortcut_name: ?string}|null  $warehouse
     * @param  array{lot_number: ?string, expiry_date: ?string}|null  $lot
     */
    public function __construct(
        public int $sortOrder,
        public string $itemCode,
        public string $itemName,
        public ?string $description,
        public ?string $uomCode,
        public string $quantity,
        public string $unitPrice,
        public string $discountPercent,
        public string $discountAmount,
        public string $taxRate,
        public string $lineSubtotal,
        public string $taxAmount,
        public string $lineTotal,
        public ?array $warehouse = null,
        public ?array $lot = null,
        public ?string $notes = null,
    ) {}

    /**
     * @return array{
     *     sort_order: int,
     *     item_code: string,
     *     item_name: string,
     *     description: ?string,
     *     uom_code: ?string,
     *     quantity: string,
     *     unit_price: string,
     *     discount_percent: string,
     *     discount_amount: string,
     *     tax_rate: string,
     *     line_subtotal: string,
     *     tax_amount: string,
     *     line_total: string,
     *     warehouse: ?array{name: ?string, shortcut_name: ?string},
     *     lot: ?array{lot_number: ?string, expiry_date: ?string},
     *     notes: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'sort_order' => $this->sortOrder,
            'item_code' => $this->itemCode,
            'item_name' => $this->itemName,
            'description' => CanonicalInvoiceFormatter::nullableString($this->description),
            'uom_code' => CanonicalInvoiceFormatter::nullableString($this->uomCode),
            'quantity' => CanonicalInvoiceFormatter::quantity($this->quantity),
            'unit_price' => CanonicalInvoiceFormatter::money($this->unitPrice),
            'discount_percent' => CanonicalInvoiceFormatter::percent($this->discountPercent),
            'discount_amount' => CanonicalInvoiceFormatter::money($this->discountAmount),
            'tax_rate' => CanonicalInvoiceFormatter::percent($this->taxRate),
            'line_subtotal' => CanonicalInvoiceFormatter::money($this->lineSubtotal),
            'tax_amount' => CanonicalInvoiceFormatter::money($this->taxAmount),
            'line_total' => CanonicalInvoiceFormatter::money($this->lineTotal),
            'warehouse' => $this->warehouse,
            'lot' => $this->lot,
            'notes' => CanonicalInvoiceFormatter::nullableString($this->notes),
        ];
    }
}
