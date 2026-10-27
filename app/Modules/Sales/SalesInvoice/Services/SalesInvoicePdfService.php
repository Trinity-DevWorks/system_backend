<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class SalesInvoicePdfService
{
    public function __construct(
        private readonly SalesInvoiceService $salesInvoiceService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(SalesInvoice $invoice, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $invoice->status,
            [SalesInvoiceStatus::Posted, SalesInvoiceStatus::Reversed],
            'Only posted or reversed sales invoices can be printed.',
            'SALES_INVOICE_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $invoice = $this->salesInvoiceService->find($invoice->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($invoice->invoice_date), $money),
        ];
        if ($invoice->due_on) {
            $meta[] = DocumentPdfSupport::metaRow($labels['due_date'], $money->date($invoice->due_on), $money);
        }
        if ($invoice->salesOrder) {
            $meta[] = DocumentPdfSupport::metaRow($labels['order_number'], (string) $invoice->salesOrder->order_number, $money);
        }
        if ($invoice->paymentMethod) {
            $meta[] = DocumentPdfSupport::metaRow($labels['payment_method'], (string) $invoice->paymentMethod->name, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($invoice->currency, $invoice->exchange_rate, $money, $labels));

        $title = $options->isArabic() ? $money->text('فاتورة مبيعات') : 'INVOICE';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($invoice->invoice_number),
            'statusWatermark' => DocumentPdfSupport::watermark($invoice->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['bill_to'], $invoice->customer, $money, 'customer_code'),
            ],
            'lineColumns' => DocumentPdfSupport::commercialColumns(),
            'lines' => DocumentPdfSupport::commercialLines($invoice->lines, $money),
            'totals' => DocumentPdfSupport::commercialTotals($invoice, $money, $labels, true),
            'notes' => $money->text($invoice->notes),
        ], $options);
    }

    public function downloadFilename(SalesInvoice $invoice): string
    {
        return DocumentPdfOptions::sanitizeFilename($invoice->invoice_number ?? '', 'sales-invoice');
    }
}
