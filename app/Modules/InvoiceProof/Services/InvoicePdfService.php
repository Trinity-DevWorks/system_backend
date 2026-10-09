<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Models\Attachment;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Serializers\PurchaseInvoiceCanonicalSerializer;
use App\Modules\InvoiceProof\Serializers\SalesInvoiceCanonicalSerializer;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use JsonException;
use Throwable;

/**
 * Renders an invoice with the same DomPDF path as a purchase order.
 * A posted invoice uses the sealed snapshot. A draft uses the live invoice.
 */
class InvoicePdfService
{
    public function __construct(
        private readonly InvoiceSnapshotService $invoiceSnapshotService,
    ) {}

    public function renderSales(SalesInvoice $invoice): \Barryvdh\DomPDF\PDF
    {
        return $this->render($this->documentForSales($invoice));
    }

    public function renderPurchase(PurchaseInvoice $invoice): \Barryvdh\DomPDF\PDF
    {
        return $this->render($this->documentForPurchase($invoice));
    }

    public function downloadFilename(string $invoiceNumber): string
    {
        $number = trim($invoiceNumber) !== '' ? $invoiceNumber : 'invoice';

        return preg_replace('/[^\w\-]+/', '-', $number).'.pdf';
    }

    /**
     * @return array<string, mixed>
     */
    private function documentForSales(SalesInvoice $invoice): array
    {
        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        $sealed = $this->decodeSnapshot($snapshot?->canonical_json);
        if ($sealed !== null) {
            $sealed['sealed'] = true;

            return $sealed;
        }

        try {
            $document = SalesInvoiceCanonicalSerializer::serialize($invoice, (string) $invoice->id)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('This invoice is not ready to download.', 0, $exception);
        }
        $document['sealed'] = false;
        $document['proof_id'] = null;

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function documentForPurchase(PurchaseInvoice $invoice): array
    {
        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        $sealed = $this->decodeSnapshot($snapshot?->canonical_json);
        if ($sealed !== null) {
            $sealed['sealed'] = true;

            return $sealed;
        }

        try {
            $document = PurchaseInvoiceCanonicalSerializer::serialize($invoice, (string) $invoice->id)->toArray();
        } catch (InvalidArgumentException $exception) {
            throw new InvalidArgumentException('This invoice is not ready to download.', 0, $exception);
        }
        $document['sealed'] = false;
        $document['proof_id'] = null;

        return $document;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeSnapshot(mixed $json): ?array
    {
        if (! is_string($json) || trim($json) === '') {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function render(array $document): \Barryvdh\DomPDF\PDF
    {
        $supplier = is_array($document['supplier'] ?? null) ? $document['supplier'] : [];
        $buyer = is_array($document['buyer'] ?? null) ? $document['buyer'] : [];
        $terms = is_array($document['payment_terms'] ?? null) ? $document['payment_terms'] : [];
        $type = ($document['invoice_type'] ?? '') === 'purchase' ? 'Purchase invoice' : 'Sales invoice';
        $number = is_string($document['invoice_number'] ?? null) && $document['invoice_number'] !== ''
            ? $document['invoice_number']
            : 'Invoice';
        $letterhead = $this->letterhead();

        return Pdf::loadView('invoices.invoice', [
            'title' => $type,
            'number' => $number,
            'invoiceDate' => $this->text($document['invoice_date'] ?? null),
            'dueOn' => $this->text($document['due_on'] ?? null),
            'currencyCode' => $this->text($document['currency_code'] ?? null),
            'paymentTerms' => $this->text($terms['name'] ?? null),
            'logoSrc' => $letterhead['logo'],
            'companyName' => $letterhead['name'],
            'companyLegalName' => $letterhead['legal_name'],
            'companyLines' => $letterhead['lines'],
            'supplierLines' => $this->partyLines($supplier),
            'buyerLines' => $this->partyLines($buyer),
            'billingLines' => $this->addressLines($document['billing_address'] ?? null),
            'shippingLines' => $this->addressLines($document['shipping_address'] ?? null),
            'lines' => $this->lines(is_array($document['lines'] ?? null) ? $document['lines'] : []),
            'subtotal' => $this->text($document['subtotal'] ?? null),
            'discountTotal' => $this->text($document['discount_total'] ?? null),
            'taxTotal' => $this->text($document['tax_total'] ?? null),
            'adjustment' => $this->text($document['adjustment'] ?? null),
            'grandTotal' => $this->text($document['grand_total'] ?? null),
            'notes' => $this->text($document['notes'] ?? null),
            'proofId' => ($document['sealed'] ?? false) === true ? $this->text($document['proof_id'] ?? null) : null,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4');
    }

    /**
     * @return array{logo: ?string, name: string, legal_name: ?string, lines: list<string>}
     */
    private function letterhead(): array
    {
        $profile = CompanyProfile::singleton()->loadMissing('logoAttachment');
        $name = $this->text($profile->company_name) ?? 'Company';
        $legal = $this->text($profile->legal_name);
        if ($legal === $name) {
            $legal = null;
        }

        $lines = [];
        $address = $this->text($profile->address);
        if ($address !== null) {
            foreach (preg_split("/\r\n|\n|\r/", $address) ?: [] as $part) {
                $part = trim((string) $part);
                if ($part !== '') {
                    $lines[] = $part;
                }
            }
        }
        foreach ([$profile->phone, $profile->email, $profile->website] as $value) {
            $text = $this->text($value);
            if ($text !== null) {
                $lines[] = $text;
            }
        }
        $tax = $this->text($profile->tax_number);
        if ($tax !== null) {
            $lines[] = 'Tax '.$tax;
        }
        $registration = $this->text($profile->registration_number);
        if ($registration !== null) {
            $lines[] = 'Registration '.$registration;
        }

        return [
            'logo' => $this->logoDataUri($profile->logoAttachment),
            'name' => $name,
            'legal_name' => $legal,
            'lines' => $lines,
        ];
    }

    private function logoDataUri(?Attachment $attachment): ?string
    {
        if ($attachment === null || ! $attachment->isDownloadable()) {
            return null;
        }

        $mime = strtolower((string) $attachment->mime_type);
        try {
            if (! Storage::disk($attachment->disk)->exists($attachment->file_path)) {
                return null;
            }
            $bytes = Storage::disk($attachment->disk)->get($attachment->file_path);
        } catch (Throwable) {
            return null;
        }
        if (! is_string($bytes) || $bytes === '') {
            return null;
        }

        $raster = $this->rasterForPdf($bytes, $mime);
        if ($raster === null) {
            return null;
        }

        return 'data:'.$raster['mime'].';base64,'.base64_encode($raster['bytes']);
    }

    /**
     * @return array{mime: string, bytes: string}|null
     */
    private function rasterForPdf(string $bytes, string $mime): ?array
    {
        if (in_array($mime, ['image/png', 'image/jpeg', 'image/gif'], true)) {
            return ['mime' => $mime, 'bytes' => $bytes];
        }
        if ($mime !== 'image/webp' || ! function_exists('imagecreatefromstring') || ! function_exists('imagepng')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $png = ob_get_clean();

        return is_string($png) && $png !== '' ? ['mime' => 'image/png', 'bytes' => $png] : null;
    }

    /**
     * @param  array<string, mixed>  $party
     * @return list<string>
     */
    private function partyLines(array $party): array
    {
        $lines = [];
        $legal = $this->text($party['legal_name'] ?? null);
        $name = $this->text($party['name'] ?? null);
        if ($legal !== null) {
            $lines[] = $legal;
        }
        if ($name !== null && $name !== $legal) {
            $lines[] = $name;
        }
        $tax = $this->text($party['tax_number'] ?? null);
        if ($tax !== null) {
            $lines[] = 'Tax '.$tax;
        }
        $email = $this->text($party['email'] ?? null);
        if ($email !== null) {
            $lines[] = $email;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function addressLines(mixed $address): array
    {
        if (! is_array($address)) {
            return [];
        }

        $lines = [];
        foreach (['address_line_1', 'address_line_2', 'city', 'state', 'country'] as $key) {
            $value = $this->text($address[$key] ?? null);
            if ($value !== null) {
                $lines[] = $value;
            }
        }
        $phone = $this->text($address['phone'] ?? null);
        if ($phone !== null) {
            $lines[] = $phone;
        }

        return $lines;
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{item: string, quantity: string, uom: string, unit_price: string, tax: string, line_total: string}>
     */
    private function lines(array $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->text($row['item_name'] ?? null) ?? '—';
            $code = $this->text($row['item_code'] ?? null);
            $lines[] = [
                'item' => $code !== null ? $code.' · '.$name : $name,
                'quantity' => $this->text($row['quantity'] ?? null) ?? '—',
                'uom' => $this->text($row['uom_code'] ?? null) ?? '—',
                'unit_price' => $this->text($row['unit_price'] ?? null) ?? '—',
                'tax' => $this->text($row['tax_amount'] ?? null) ?? '—',
                'line_total' => $this->text($row['line_total'] ?? null) ?? '—',
            ];
        }

        return $lines;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
