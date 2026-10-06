<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Serializers;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\InvoiceProof\CanonicalInvoiceSchema;
use App\Modules\InvoiceProof\DTOs\CanonicalInvoiceData;
use App\Modules\InvoiceProof\DTOs\CanonicalInvoiceLineData;
use App\Modules\InvoiceProof\DTOs\CanonicalPartyData;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Support\CanonicalInvoiceFormatter;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Warehouse\Models\Warehouse;
use InvalidArgumentException;

/**
 * Maps a purchase invoice into Canonical Invoice Schema v3.
 *
 * Vendor becomes supplier; company profile becomes buyer. Omits paid_total
 * and PO/GRN ids. Does not save or hash.
 */
final class PurchaseInvoiceCanonicalSerializer
{
    public static function serialize(PurchaseInvoice $invoice, string $proofId, ?CompanyProfile $company = null): CanonicalInvoiceData
    {
        $invoice->loadMissing([
            'supplier',
            'currency',
            'paymentMethod',
            'paymentTerm',
            'warehouse',
            'lines.item',
            'lines.itemUom.uom',
            'lines.warehouse',
            'lines.lot',
        ]);

        $company ??= CompanyProfile::singleton();
        $vendor = $invoice->supplier;
        $currency = $invoice->currency;

        if ($vendor === null) {
            throw new InvalidArgumentException('Purchase invoice is missing a supplier for canonical serialization.');
        }

        if ($currency === null || CanonicalInvoiceFormatter::nullableString($currency->code) === null) {
            throw new InvalidArgumentException('Purchase invoice is missing a currency code for canonical serialization.');
        }

        $invoiceDate = CanonicalInvoiceFormatter::date($invoice->invoice_date);
        if ($invoiceDate === null) {
            throw new InvalidArgumentException('Purchase invoice is missing an invoice date for canonical serialization.');
        }

        $supplierName = CanonicalInvoiceFormatter::nullableString($vendor->company_name)
            ?? CanonicalInvoiceFormatter::nullableString($vendor->name);
        if ($supplierName === null) {
            throw new InvalidArgumentException('Supplier is missing a name for canonical serialization.');
        }

        $buyerName = CanonicalInvoiceFormatter::nullableString($company->company_name);
        if ($buyerName === null) {
            throw new InvalidArgumentException('Company profile is missing a company name for canonical serialization.');
        }

        return new CanonicalInvoiceData(
            proofId: $proofId,
            invoiceType: InvoiceProofType::Purchase,
            invoiceNumber: CanonicalInvoiceFormatter::nullableString($invoice->invoice_number),
            invoiceDate: $invoiceDate,
            dueOn: CanonicalInvoiceFormatter::date($invoice->due_on),
            currencyCode: (string) $currency->code,
            exchangeRate: CanonicalInvoiceFormatter::rate($invoice->exchange_rate),
            supplier: new CanonicalPartyData(
                name: $supplierName,
                legalName: CanonicalInvoiceFormatter::nullableString($vendor->company_name),
                taxNumber: CanonicalInvoiceFormatter::nullableString($vendor->vat_number),
                email: CanonicalInvoiceFormatter::nullableString($vendor->email),
            ),
            buyer: new CanonicalPartyData(
                name: $buyerName,
                legalName: CanonicalInvoiceFormatter::nullableString($company->legal_name),
                taxNumber: CanonicalInvoiceFormatter::nullableString($company->tax_number),
                email: CanonicalInvoiceFormatter::nullableString($company->email),
            ),
            lines: self::lines($invoice),
            subtotal: CanonicalInvoiceFormatter::money($invoice->subtotal),
            discountTotal: CanonicalInvoiceFormatter::money($invoice->discount_total),
            taxTotal: CanonicalInvoiceFormatter::money($invoice->tax_total),
            adjustment: CanonicalInvoiceFormatter::money($invoice->adjustment),
            grandTotal: CanonicalInvoiceFormatter::money($invoice->grand_total),
            notes: CanonicalInvoiceFormatter::nullableString($invoice->notes),
            salesman: null,
            paymentMethod: self::paymentMethod($invoice->paymentMethod),
            paymentTerms: self::paymentTerms($invoice->paymentTerm),
            warehouse: self::warehouse($invoice->warehouse),
            billingAddress: null,
            shippingAddress: null,
        );
    }

    /**
     * @return list<CanonicalInvoiceLineData>
     */
    private static function lines(PurchaseInvoice $invoice): array
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
                throw new InvalidArgumentException('Purchase invoice line is missing an item code for canonical serialization.');
            }

            $itemName = CanonicalInvoiceFormatter::nullableString($line->item?->name);
            if ($itemName === null) {
                throw new InvalidArgumentException('Purchase invoice line is missing an item name for canonical serialization.');
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
