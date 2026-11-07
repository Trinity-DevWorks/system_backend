<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\Services;

use App\Modules\Inventory\Purchasing\SupplierRefund\Enums\SupplierRefundStatus;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefund;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class SupplierRefundPdfService
{
    public function __construct(
        private readonly SupplierRefundService $supplierRefundService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(SupplierRefund $refund, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $refund->status,
            [SupplierRefundStatus::Posted, SupplierRefundStatus::Reversed],
            'Only posted or reversed supplier refunds can be printed.',
            'SUPPLIER_REFUND_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $refund = $this->supplierRefundService->find($refund->id);

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

        $title = $options->isArabic() ? $money->text('استرداد مورد') : 'SUPPLIER REFUND';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($refund->refund_number),
            'statusWatermark' => DocumentPdfSupport::watermark($refund->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['supplier'], $refund->supplier, $money, 'supplier_code'),
            ],
            'lineColumns' => [],
            'lines' => [],
            'allocations' => DocumentPdfSupport::allocationRows(
                $refund->allocations,
                $money,
                static fn (object $allocation): ?string => $allocation->purchaseDebitNote?->debit_note_number,
            ),
            'totals' => DocumentPdfSupport::paymentTotals($refund->amount, $refund->allocations, $money, $labels),
            'notes' => $money->text($refund->notes),
        ], $options);
    }

    public function downloadFilename(SupplierRefund $refund): string
    {
        return DocumentPdfOptions::sanitizeFilename($refund->refund_number ?? '', 'supplier-refund');
    }
}
