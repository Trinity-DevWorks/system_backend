<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Services;

use App\Modules\Sales\CustomerRefund\Enums\CustomerRefundStatus;
use App\Modules\Sales\CustomerRefund\Models\CustomerRefund;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class CustomerRefundPdfService
{
    public function __construct(
        private readonly CustomerRefundService $customerRefundService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(CustomerRefund $refund, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $refund->status,
            [CustomerRefundStatus::Posted, CustomerRefundStatus::Reversed],
            'Only posted or reversed customer refunds can be printed.',
            'CUSTOMER_REFUND_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $refund = $this->customerRefundService->find($refund->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($refund->refund_date), $money),
        ];
        if ($refund->paymentMethod) {
            $meta[] = DocumentPdfSupport::metaRow($labels['payment_method'], (string) $refund->paymentMethod->name, $money);
        }
        if (! empty($refund->reference)) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $refund->reference, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($refund->currency, $refund->exchange_rate, $money, $labels));

        $title = $options->isArabic() ? $money->text('استرداد عميل') : 'CUSTOMER REFUND';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($refund->refund_number),
            'statusWatermark' => DocumentPdfSupport::watermark($refund->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['customer'], $refund->customer, $money, 'customer_code'),
            ],
            'lineColumns' => [],
            'lines' => [],
            'allocations' => DocumentPdfSupport::allocationRows(
                $refund->allocations,
                $money,
                static fn (object $allocation): ?string => $allocation->salesCreditNote?->credit_note_number,
            ),
            'totals' => DocumentPdfSupport::paymentTotals($refund->amount, $refund->allocations, $money, $labels),
            'notes' => $money->text($refund->notes),
        ], $options);
    }

    public function downloadFilename(CustomerRefund $refund): string
    {
        return DocumentPdfOptions::sanitizeFilename($refund->refund_number ?? '', 'customer-refund');
    }
}
