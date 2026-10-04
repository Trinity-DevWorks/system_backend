<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\DTOs;

use App\Modules\Currency\Models\Currency;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use InvalidArgumentException;
use JsonException;

/**
 * Clerk-safe buyer portal payload. Commercial fields come from the sealed
 * snapshot, not the live ERP invoice. Includes content_hash so the buyer can
 * see the seal they are binding to. No snapshot JSON or company-approve flags.
 */
readonly class InvoiceProofPortalData
{
    /**
     * @param  list<array{
     *     item_name: ?string,
     *     description: ?string,
     *     item_code: ?string,
     *     quantity: string,
     *     uom: ?string,
     *     unit_price: string,
     *     discount_percent: string,
     *     tax_rate: string,
     *     line_total: string
     * }>  $lines
     * @param  array{
     *     domain: array{name: string, version: string, chain_id: int, verifying_contract: string},
     *     primary_type: string,
     *     types: array{BuyerApproval: list<array{name: string, type: string}>},
     *     message: array{proof_id: string, content_hash: string, invoice_number: string}
     * }|null  $eip712
     */
    public function __construct(
        public string $id,
        public string $companyName,
        public ?string $customerName,
        public ?string $invoiceNumber,
        public ?string $invoiceDate,
        public ?string $dueOn,
        public string $subtotal,
        public string $discountTotal,
        public string $taxTotal,
        public string $adjustment,
        public string $grandTotal,
        public string $netToPay,
        public ?string $currencyCode,
        public ?string $currencySymbol,
        public string $status,
        public array $lines,
        public ?string $contentHash,
        public ?int $chainId,
        public ?string $contractAddress,
        public ?string $buyerWallet,
        public ?string $proofId,
        public ?array $eip712,
        public bool $canApproveAsBuyer,
        public bool $locked = false,
        public ?string $registeredAt = null,
        public ?string $supplierApprovedAt = null,
        public ?string $buyerApprovedAt = null,
        public ?string $supplierWallet = null,
        public array $otherInvoices = [],
        public ?string $buyerWalletType = null,
        public ?string $financedAt = null,
        public ?string $revokedAt = null,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $otherInvoices
     */
    public static function fromSnapshot(
        SalesInvoice $invoice,
        InvoiceSnapshot $snapshot,
        InvoiceProofVerificationData $proof,
        array $otherInvoices = [],
    ): self {
        try {
            $canonical = json_decode($snapshot->canonical_json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            abort(404);
        }

        if (! is_array($canonical)) {
            abort(404);
        }

        $currencyCode = self::stringOrNull($canonical['currency_code'] ?? null);
        $grandTotal = self::stringOrEmpty($canonical['grand_total'] ?? null);
        $supplier = is_array($canonical['supplier'] ?? null) ? $canonical['supplier'] : [];
        $buyer = is_array($canonical['buyer'] ?? null) ? $canonical['buyer'] : [];

        return new self(
            id: (string) $invoice->id,
            companyName: self::stringOrEmpty($supplier['name'] ?? null),
            customerName: self::stringOrNull($buyer['name'] ?? null),
            invoiceNumber: self::stringOrNull($canonical['invoice_number'] ?? null),
            invoiceDate: self::stringOrNull($canonical['invoice_date'] ?? null),
            dueOn: self::stringOrNull($canonical['due_on'] ?? null),
            subtotal: self::stringOrEmpty($canonical['subtotal'] ?? null),
            discountTotal: self::stringOrEmpty($canonical['discount_total'] ?? null),
            taxTotal: self::stringOrEmpty($canonical['tax_total'] ?? null),
            adjustment: self::stringOrEmpty($canonical['adjustment'] ?? null),
            grandTotal: $grandTotal,
            netToPay: $grandTotal,
            currencyCode: $currencyCode,
            currencySymbol: self::currencySymbol($currencyCode),
            status: $proof->status->value,
            lines: self::lines($canonical['lines'] ?? null),
            contentHash: self::contentHash($snapshot->content_hash),
            chainId: $proof->chainId,
            contractAddress: $proof->contractAddress,
            buyerWallet: $proof->buyerWallet,
            buyerWalletType: $proof->buyerWalletType,
            supplierWallet: $proof->supplierWallet,
            proofId: $proof->proofId,
            eip712: $proof->canApproveAsBuyer ? $proof->eip712 : null,
            canApproveAsBuyer: $proof->canApproveAsBuyer,
            registeredAt: $proof->registeredAt,
            supplierApprovedAt: $proof->supplierApprovedAt,
            buyerApprovedAt: $proof->buyerApprovedAt,
            otherInvoices: $otherInvoices,
            financedAt: $proof->financedAt(),
            revokedAt: $proof->revokedAt,
        );
    }

    /**
     * @return list<array{
     *     item_name: ?string,
     *     description: ?string,
     *     item_code: ?string,
     *     quantity: string,
     *     uom: ?string,
     *     unit_price: string,
     *     discount_percent: string,
     *     tax_rate: string,
     *     line_total: string
     * }>
     */
    private static function lines(mixed $rawLines): array
    {
        if (! is_array($rawLines)) {
            return [];
        }

        $lines = [];
        foreach ($rawLines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $lines[] = [
                'item_name' => self::stringOrNull($line['item_name'] ?? null),
                'description' => self::stringOrNull($line['description'] ?? null),
                'item_code' => self::stringOrNull($line['item_code'] ?? null),
                'quantity' => self::stringOrEmpty($line['quantity'] ?? null),
                'uom' => self::stringOrNull($line['uom_code'] ?? null),
                'unit_price' => self::stringOrEmpty($line['unit_price'] ?? null),
                'discount_percent' => self::stringOrEmpty($line['discount_percent'] ?? null),
                'tax_rate' => self::stringOrEmpty($line['tax_rate'] ?? null),
                'line_total' => self::stringOrEmpty($line['line_total'] ?? null),
            ];
        }

        return $lines;
    }

    private static function contentHash(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return InvoiceProofBytes::normalizedContentHash($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private static function currencySymbol(?string $currencyCode): ?string
    {
        if ($currencyCode === null) {
            return null;
        }

        $symbol = Currency::query()->where('code', $currencyCode)->value('symbol');

        return is_string($symbol) && $symbol !== '' ? $symbol : null;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * @return array{
     *     id: string,
     *     company_name: string,
     *     customer_name: ?string,
     *     invoice_number: ?string,
     *     invoice_date: ?string,
     *     due_on: ?string,
     *     subtotal: string,
     *     discount_total: string,
     *     tax_total: string,
     *     adjustment: string,
     *     grand_total: string,
     *     net_to_pay: string,
     *     currency_code: ?string,
     *     currency_symbol: ?string,
     *     status: string,
     *     lines: list<array<string, mixed>>,
     *     content_hash: ?string,
     *     chain_id: ?int,
     *     contract_address: ?string,
     *     buyer_wallet: ?string,
     *     buyer_wallet_type: ?string,
     *     supplier_wallet: ?string,
     *     proof_id: ?string,
     *     eip712: ?array<string, mixed>,
     *     can_approve_as_buyer: bool,
     *     locked: false,
     *     registered_at: ?string,
     *     supplier_approved_at: ?string,
     *     buyer_approved_at: ?string,
     *     financed_at: ?string,
     *     revoked_at: ?string,
     *     other_invoices: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'company_name' => $this->companyName,
            'customer_name' => $this->customerName,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => $this->invoiceDate,
            'due_on' => $this->dueOn,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discountTotal,
            'tax_total' => $this->taxTotal,
            'adjustment' => $this->adjustment,
            'grand_total' => $this->grandTotal,
            'net_to_pay' => $this->netToPay,
            'currency_code' => $this->currencyCode,
            'currency_symbol' => $this->currencySymbol,
            'status' => $this->status,
            'lines' => $this->lines,
            'content_hash' => $this->contentHash,
            'chain_id' => $this->chainId,
            'contract_address' => $this->contractAddress,
            'buyer_wallet' => $this->buyerWallet,
            'buyer_wallet_type' => $this->buyerWalletType,
            'supplier_wallet' => $this->supplierWallet,
            'proof_id' => $this->proofId,
            'eip712' => $this->eip712,
            'can_approve_as_buyer' => $this->canApproveAsBuyer,
            'locked' => false,
            'registered_at' => $this->registeredAt,
            'supplier_approved_at' => $this->supplierApprovedAt,
            'buyer_approved_at' => $this->buyerApprovedAt,
            'financed_at' => $this->financedAt,
            'revoked_at' => $this->revokedAt,
            'other_invoices' => $this->otherInvoices,
        ];
    }
}
