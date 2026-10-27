<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class PurchaseInvoicePdfService
{
    public function __construct(
        private readonly PurchaseInvoiceService $purchaseInvoiceService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(PurchaseInvoice $invoice, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $invoice->status,
            [PurchaseInvoiceStatus::Posted, PurchaseInvoiceStatus::Reversed],
            'Only posted or reversed purchase invoices can be printed.',
            'PURCHASE_INVOICE_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $invoice = $this->purchaseInvoiceService->find($invoice->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($invoice->invoice_date), $money),
        ];
        if ($invoice->due_on) {
            $meta[] = DocumentPdfSupport::metaRow($labels['due_date'], $money->date($invoice->due_on), $money);
        }
        if ($invoice->purchaseOrder) {
            $meta[] = DocumentPdfSupport::metaRow($labels['po_number'], (string) $invoice->purchaseOrder->po_number, $money);
        }
        if ($invoice->goodsReceipt) {
            $meta[] = DocumentPdfSupport::metaRow($labels['grn_number'], (string) $invoice->goodsReceipt->grn_number, $money);
        }
        if (! empty($invoice->reference_2)) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $invoice->reference_2, $money);
        }
        if ($invoice->paymentMethod) {
            $meta[] = DocumentPdfSupport::metaRow($labels['payment_method'], (string) $invoice->paymentMethod->name, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($invoice->currency, $invoice->exchange_rate, $money, $labels));

        $totals = DocumentPdfSupport::commercialTotals($invoice, $money, $labels);
        $totals[] = ['label' => $labels['paid_total'], 'value' => $money->money($invoice->paid_total ?? 0)];
        $totals[] = ['label' => $labels['debited_total'], 'value' => $money->money($invoice->debited_total ?? 0)];
        $totals[] = ['label' => $labels['net_to_pay'], 'value' => $money->money($invoice->net_to_pay ?? 0), 'emphasis' => true];

        $title = $options->isArabic() ? $money->text('فاتورة مشتريات') : 'PURCHASE INVOICE';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($invoice->invoice_number),
            'statusWatermark' => DocumentPdfSupport::watermark($invoice->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['supplier'], $invoice->supplier, $money, 'supplier_code'),
                DocumentPdfSupport::warehouseBlock($labels['warehouse'], $invoice->warehouse, $money),
            ],
            'lineColumns' => DocumentPdfSupport::commercialColumns(),
            'lines' => DocumentPdfSupport::commercialLines($invoice->lines, $money),
            'totals' => $totals,
            'notes' => $money->text($invoice->notes),
        ], $options);
    }

    public function downloadFilename(PurchaseInvoice $invoice): string
    {
        return DocumentPdfOptions::sanitizeFilename($invoice->invoice_number ?? '', 'purchase-invoice');
    }
}
