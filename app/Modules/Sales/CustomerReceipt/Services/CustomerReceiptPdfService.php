<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\Services;

use App\Modules\Sales\CustomerReceipt\Enums\CustomerReceiptStatus;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class CustomerReceiptPdfService
{
    public function __construct(
        private readonly CustomerReceiptService $customerReceiptService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(CustomerReceipt $receipt, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $receipt->status,
            [CustomerReceiptStatus::Posted, CustomerReceiptStatus::Reversed],
            'Only posted or reversed customer receipts can be printed.',
            'CUSTOMER_RECEIPT_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $receipt = $this->customerReceiptService->find($receipt->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($receipt->payment_date), $money),
        ];
        if ($receipt->paymentMethod) {
            $meta[] = DocumentPdfSupport::metaRow($labels['payment_method'], (string) $receipt->paymentMethod->name, $money);
        }
        if (! empty($receipt->reference)) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $receipt->reference, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($receipt->currency, $receipt->exchange_rate, $money, $labels));

        $title = $options->isArabic() ? $money->text('سند قبض') : 'RECEIPT';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($receipt->receipt_number),
            'statusWatermark' => DocumentPdfSupport::watermark($receipt->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['customer'], $receipt->customer, $money, 'customer_code'),
            ],
            'lineColumns' => [],
            'lines' => [],
            'allocations' => DocumentPdfSupport::allocationRows(
                $receipt->allocations,
                $money,
                static fn (object $allocation): ?string => $allocation->salesInvoice?->invoice_number,
            ),
            'totals' => DocumentPdfSupport::paymentTotals($receipt->amount, $receipt->allocations, $money, $labels),
            'notes' => $money->text($receipt->notes),
        ], $options);
    }

    public function downloadFilename(CustomerReceipt $receipt): string
    {
        return DocumentPdfOptions::sanitizeFilename($receipt->receipt_number ?? '', 'customer-receipt');
    }
}
