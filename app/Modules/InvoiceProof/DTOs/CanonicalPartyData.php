<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\InvoiceProof\Support\CanonicalInvoiceFormatter;

/**
 * One party on the proof (supplier or buyer). Role comes from the parent
 * invoice JSON keys, not from whether the source document is sales or purchase.
 */
readonly class CanonicalPartyData
{
    public function __construct(
        public string $name,
        public ?string $legalName,
        public ?string $taxNumber,
        public ?string $email,
    ) {}

    /**
     * @return array{name: string, legal_name: ?string, tax_number: ?string, email: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => CanonicalInvoiceFormatter::nullableString($this->legalName),
            'tax_number' => CanonicalInvoiceFormatter::nullableString($this->taxNumber),
            'email' => CanonicalInvoiceFormatter::nullableString($this->email),
        ];
    }
}
