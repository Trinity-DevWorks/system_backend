<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Serializers;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\DTOs\CanonicalInvoiceData;
use App\Modules\InvoiceProof\DTOs\CanonicalInvoiceLineData;
use App\Modules\InvoiceProof\DTOs\CanonicalPartyData;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceFormatter;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Warehouse\Models\Warehouse;
use InvalidArgumentException;

/**
 * Maps a sales invoice into Canonical Invoice Schema v1.
 *
 * Company profile becomes supplier; customer becomes buyer. Copies posted
 * address, warehouse, lot, salesman, and payment text. Leaves out balances
 * that change after posting. Does not save or hash.
 */
final class SalesInvoiceCanonicalSerializer
{
    public static function serialize(SalesInvoice $invoice, string $proofId, ?CompanyProfile $company = null): CanonicalInvoiceData
    {
        $invoice->loadMissing([
            'customer',
            'currency',
            'salesman',
            'paymentMethod',
            'paymentTerm',
            'warehouse',
            'lines.item',
            'lines.itemUom.uom',
            'lines.warehouse',
            'lines.lot',
        ]);

        $company ??= CompanyProfile::singleton();
        $customer = $invoice->customer;
        $currency = $invoice->currency;

        if ($customer === null) {
            throw new InvalidArgumentException('Sales invoice is missing a customer for canonical serialization.');
        }

        if ($currency === null || CanonicalInvoiceFormatter::nullableString($currency->code) === null) {
            throw new InvalidArgumentException('Sales invoice is missing a currency code for canonical serialization.');
        }

        $invoiceDate = CanonicalInvoiceFormatter::date($invoice->invoice_date);
        if ($invoiceDate === null) {
            throw new InvalidArgumentException('Sales invoice is missing an invoice date for canonical serialization.');
        }

        $supplierName = CanonicalInvoiceFormatter::nullableString($company->company_name);
        if ($supplierName === null) {
            throw new InvalidArgumentException('Company profile is missing a company name for canonical serialization.');
        }

        $buyerName = CanonicalInvoiceFormatter::nullableString($customer->name);
        if ($buyerName === null) {
            throw new InvalidArgumentException('Customer is missing a name for canonical serialization.');
        }

        return new CanonicalInvoiceData(
            proofId: $proofId,
            invoiceType: InvoiceProofType::Sales,
            invoiceNumber: CanonicalInvoiceFormatter::nullableString($invoice->invoice_number),
            invoiceDate: $invoiceDate,
            dueOn: CanonicalInvoiceFormatter::date($invoice->due_on),
            currencyCode: (string) $currency->code,
            exchangeRate: CanonicalInvoiceFormatter::rate($invoice->exchange_rate),
            supplier: new CanonicalPartyData(
                name: $supplierName,
                legalName: CanonicalInvoiceFormatter::nullableString($company->legal_name),
                taxNumber: CanonicalInvoiceFormatter::nullableString($company->tax_number),
                email: CanonicalInvoiceFormatter::nullableString($company->email),
            ),
            buyer: new CanonicalPartyData(
                name: $buyerName,
                legalName: null,
                taxNumber: CanonicalInvoiceFormatter::nullableString($customer->vat_number),
                email: CanonicalInvoiceFormatter::nullableString($customer->email),
            ),
            lines: self::lines($invoice),
            subtotal: CanonicalInvoiceFormatter::money($invoice->subtotal),
            discountTotal: CanonicalInvoiceFormatter::money($invoice->discount_total),
            taxTotal: CanonicalInvoiceFormatter::money($invoice->tax_total),
            adjustment: CanonicalInvoiceFormatter::money($invoice->adjustment),
            grandTotal: CanonicalInvoiceFormatter::money($invoice->grand_total),
            notes: CanonicalInvoiceFormatter::nullableString($invoice->notes),
            salesman: self::salesman($invoice->salesman),
            paymentMethod: self::paymentMethod($invoice->paymentMethod),
            paymentTerms: self::paymentTerms($invoice->paymentTerm),
            warehouse: self::warehouse($invoice->warehouse),
            billingAddress: self::address($invoice->billing_address),
            shippingAddress: self::address($invoice->shipping_address),
        );
    }

    /**
     * @return list<CanonicalInvoiceLineData>
     */
    private static function lines(SalesInvoice $invoice): array
    {
        $sorted = $invoice->lines
            ->sortBy([
                ['sort_order', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        $canonical = [];
        foreach ($sorted as $line) {
            $itemCode = CanonicalInvoiceFormatter::nullableString($line->item?->item_code);
            if ($itemCode === null) {
                throw new InvalidArgumentException('Sales invoice line is missing an item code for canonical serialization.');
            }

            $itemName = CanonicalInvoiceFormatter::nullableString($line->item?->name);
            if ($itemName === null) {
                throw new InvalidArgumentException('Sales invoice line is missing an item name for canonical serialization.');
            }

            $description = CanonicalInvoiceFormatter::nullableString($line->item?->description)
                ?? CanonicalInvoiceFormatter::nullableString($line->description);

            $canonical[] = new CanonicalInvoiceLineData(
                sortOrder: (int) $line->sort_order,
                itemCode: $itemCode,
                itemName: $itemName,
                description: $description,
                uomCode: CanonicalInvoiceFormatter::nullableString($line->itemUom?->uom?->code),
                quantity: CanonicalInvoiceFormatter::quantity($line->quantity),
                unitPrice: CanonicalInvoiceFormatter::money($line->unit_price),
                discountPercent: CanonicalInvoiceFormatter::percent($line->discount_percent),
                discountAmount: CanonicalInvoiceFormatter::money($line->discount_amount),
                taxRate: CanonicalInvoiceFormatter::percent($line->tax_rate),
                lineSubtotal: CanonicalInvoiceFormatter::money($line->line_subtotal),
                taxAmount: CanonicalInvoiceFormatter::money($line->tax_amount),
                lineTotal: CanonicalInvoiceFormatter::money($line->line_total),
                warehouse: self::warehouse($line->warehouse),
                lot: self::lot($line->lot),
                notes: CanonicalInvoiceFormatter::nullableString($line->notes),
            );
        }

        return $canonical;
    }

    /**
     * @return array{code: ?string, name: ?string}|null
     */
    private static function salesman(?Salesman $salesman): ?array
    {
        if ($salesman === null) {
            return null;
        }

        $row = [
            'code' => CanonicalInvoiceFormatter::nullableString($salesman->salesman_code),
            'name' => CanonicalInvoiceFormatter::nullableString($salesman->full_name),
        ];

        return self::hasText($row) ? $row : null;
    }

    /**
     * @return array{code: ?string, name: ?string}|null
     */
    private static function paymentMethod(?PaymentMethod $method): ?array
    {
        if ($method === null) {
            return null;
        }

        $row = [
            'code' => CanonicalInvoiceFormatter::nullableString($method->code),
            'name' => CanonicalInvoiceFormatter::nullableString($method->name),
        ];

        return self::hasText($row) ? $row : null;
    }

    /**
     * @return array{code: ?string, name: ?string, due_days: int}|null
     */
    private static function paymentTerms(?PaymentTerm $term): ?array
    {
        if ($term === null) {
            return null;
        }

        $code = CanonicalInvoiceFormatter::nullableString($term->code);
        $name = CanonicalInvoiceFormatter::nullableString($term->name);
        if ($code === null && $name === null) {
            return null;
        }

        return [
            'code' => $code,
            'name' => $name,
            'due_days' => (int) $term->due_days,
        ];
    }

    /**
     * @return array{name: ?string, shortcut_name: ?string}|null
     */
    private static function warehouse(?Warehouse $warehouse): ?array
    {
        if ($warehouse === null) {
            return null;
        }

        $row = [
            'name' => CanonicalInvoiceFormatter::nullableString($warehouse->name),
            'shortcut_name' => CanonicalInvoiceFormatter::nullableString($warehouse->shortcut_name),
        ];

        return self::hasText($row) ? $row : null;
    }

    /**
     * @return array{address_line_1: ?string, address_line_2: ?string, city: ?string, state: ?string, country: ?string, phone: ?string}|null
     */
    private static function address(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $row = [];
        foreach (CanonicalInvoiceSchema::ADDRESS_KEYS as $key) {
            $row[$key] = CanonicalInvoiceFormatter::nullableString($raw[$key] ?? null);
        }

        return self::hasText($row) ? $row : null;
    }

    /**
     * @return array{lot_number: ?string, expiry_date: ?string}|null
     */
    private static function lot(?InventoryLot $lot): ?array
    {
        if ($lot === null) {
            return null;
        }

        $row = [
            'lot_number' => CanonicalInvoiceFormatter::nullableString($lot->lot_number),
            'expiry_date' => CanonicalInvoiceFormatter::date($lot->expiry_date),
        ];

        return self::hasText($row) ? $row : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function hasText(array $row): bool
    {
        foreach ($row as $value) {
            if (is_string($value) && $value !== '') {
                return true;
            }
        }

        return false;
    }
}
