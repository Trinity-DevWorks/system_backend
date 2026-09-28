<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceFormatter;
use InvalidArgumentException;
use JsonException;

/**
 * The full Canonical Invoice Schema document. Assembles header, parties,
 * lines, and totals into a stable array/JSON string that later phases hash.
 */
readonly class CanonicalInvoiceData
{
    /**
     * @param  list<CanonicalInvoiceLineData>  $lines
     * @param  array{code: ?string, name: ?string}|null  $salesman
     * @param  array{code: ?string, name: ?string}|null  $paymentMethod
     * @param  array{code: ?string, name: ?string, due_days: int}|null  $paymentTerms
     * @param  array{name: ?string, shortcut_name: ?string}|null  $warehouse
     * @param  array{address_line_1: ?string, address_line_2: ?string, city: ?string, state: ?string, country: ?string, phone: ?string}|null  $billingAddress
     * @param  array{address_line_1: ?string, address_line_2: ?string, city: ?string, state: ?string, country: ?string, phone: ?string}|null  $shippingAddress
     */
    public function __construct(
        public string $proofId,
        public InvoiceProofType $invoiceType,
        public ?string $invoiceNumber,
        public string $invoiceDate,
        public ?string $dueOn,
        public string $currencyCode,
        public string $exchangeRate,
        public CanonicalPartyData $supplier,
        public CanonicalPartyData $buyer,
        public array $lines,
        public string $subtotal,
        public string $discountTotal,
        public string $taxTotal,
        public string $adjustment,
        public string $grandTotal,
        public ?string $notes,
        public ?array $salesman = null,
        public ?array $paymentMethod = null,
        public ?array $paymentTerms = null,
        public ?array $warehouse = null,
        public ?array $billingAddress = null,
        public ?array $shippingAddress = null,
        public int $schemaVersion = CanonicalInvoiceSchema::VERSION,
    ) {
        if ($this->schemaVersion !== CanonicalInvoiceSchema::VERSION) {
            throw new InvalidArgumentException('Unsupported canonical invoice schema version.');
        }

        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $this->proofId)) {
            throw new InvalidArgumentException('Canonical invoice proof_id must be a UUID.');
        }
    }

    /**
     * @return array{
     *     schema_version: int,
     *     proof_id: string,
     *     invoice_type: string,
     *     invoice_number: ?string,
     *     invoice_date: string,
     *     due_on: ?string,
     *     currency_code: string,
     *     exchange_rate: string,
     *     supplier: array{name: string, legal_name: ?string, tax_number: ?string, email: ?string},
     *     buyer: array{name: string, legal_name: ?string, tax_number: ?string, email: ?string},
     *     salesman: ?array{code: ?string, name: ?string},
     *     payment_method: ?array{code: ?string, name: ?string},
     *     payment_terms: ?array{code: ?string, name: ?string, due_days: int},
     *     warehouse: ?array{name: ?string, shortcut_name: ?string},
     *     billing_address: ?array{address_line_1: ?string, address_line_2: ?string, city: ?string, state: ?string, country: ?string, phone: ?string},
     *     shipping_address: ?array{address_line_1: ?string, address_line_2: ?string, city: ?string, state: ?string, country: ?string, phone: ?string},
     *     lines: list<array<string, mixed>>,
     *     subtotal: string,
     *     discount_total: string,
     *     tax_total: string,
     *     adjustment: string,
     *     grand_total: string,
     *     notes: ?string
     * }
     */
    public function toArray(): array
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $lines[] = $line->toArray();
        }

        return [
            'schema_version' => $this->schemaVersion,
            'proof_id' => $this->proofId,
            'invoice_type' => $this->invoiceType->value,
            'invoice_number' => CanonicalInvoiceFormatter::nullableString($this->invoiceNumber),
            'invoice_date' => $this->invoiceDate,
            'due_on' => CanonicalInvoiceFormatter::date($this->dueOn),
            'currency_code' => $this->currencyCode,
            'exchange_rate' => CanonicalInvoiceFormatter::rate($this->exchangeRate),
            'supplier' => $this->supplier->toArray(),
            'buyer' => $this->buyer->toArray(),
            'salesman' => $this->salesman,
            'payment_method' => $this->paymentMethod,
            'payment_terms' => $this->paymentTerms,
            'warehouse' => $this->warehouse,
            'billing_address' => $this->billingAddress,
            'shipping_address' => $this->shippingAddress,
            'lines' => $lines,
            'subtotal' => CanonicalInvoiceFormatter::money($this->subtotal),
            'discount_total' => CanonicalInvoiceFormatter::money($this->discountTotal),
            'tax_total' => CanonicalInvoiceFormatter::money($this->taxTotal),
            'adjustment' => CanonicalInvoiceFormatter::money($this->adjustment),
            'grand_total' => CanonicalInvoiceFormatter::money($this->grandTotal),
            'notes' => CanonicalInvoiceFormatter::nullableString($this->notes),
        ];
    }

    /**
     * @throws JsonException
     */
    public function toJson(): string
    {
        return CanonicalInvoiceFormatter::encode($this->toArray());
    }
}
