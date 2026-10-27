<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\Services;

use App\Modules\Inventory\Purchasing\SupplierPayment\Enums\SupplierPaymentStatus;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class SupplierPaymentPdfService
{
    public function __construct(
        private readonly SupplierPaymentService $supplierPaymentService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(SupplierPayment $payment, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $payment->status,
            [SupplierPaymentStatus::Posted, SupplierPaymentStatus::Reversed],
            'Only posted or reversed supplier payments can be printed.',
            'SUPPLIER_PAYMENT_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $payment = $this->supplierPaymentService->find($payment->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($payment->payment_date), $money),
        ];
        if ($payment->paymentMethod) {
            $meta[] = DocumentPdfSupport::metaRow($labels['payment_method'], (string) $payment->paymentMethod->name, $money);
        }
        if (! empty($payment->reference)) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $payment->reference, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($payment->currency, $payment->exchange_rate, $money, $labels));

        $title = $options->isArabic() ? $money->text('سند صرف') : 'PAYMENT';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($payment->payment_number),
            'statusWatermark' => DocumentPdfSupport::watermark($payment->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['pay_to'], $payment->supplier, $money, 'supplier_code'),
            ],
            'lineColumns' => [],
            'lines' => [],
            'allocations' => DocumentPdfSupport::allocationRows(
                $payment->allocations,
                $money,
                static fn (object $allocation): ?string => $allocation->purchaseInvoice?->invoice_number,
            ),
            'totals' => DocumentPdfSupport::paymentTotals($payment->amount, $payment->allocations, $money, $labels),
            'notes' => $money->text($payment->notes),
        ], $options);
    }

    public function downloadFilename(SupplierPayment $payment): string
    {
        return DocumentPdfOptions::sanitizeFilename($payment->payment_number ?? '', 'supplier-payment');
    }
}
