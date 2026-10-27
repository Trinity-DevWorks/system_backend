<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Services;

use App\Modules\Sales\SalesQuotation\Enums\SalesQuotationStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class SalesQuotationPdfService
{
    public function __construct(
        private readonly SalesQuotationService $salesQuotationService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(SalesQuotation $quotation, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $quotation->status,
            [SalesQuotationStatus::Sent, SalesQuotationStatus::Accepted, SalesQuotationStatus::Converted],
            'Only sent, accepted, or converted sales quotations can be printed.',
            'SALES_QUOTATION_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $quotation = $this->salesQuotationService->find($quotation->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($quotation->quotation_date), $money),
        ];
        if ($quotation->valid_until) {
            $meta[] = DocumentPdfSupport::metaRow($labels['validity'], $money->date($quotation->valid_until), $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($quotation->currency, $quotation->exchange_rate, $money, $labels));

        $title = $options->isArabic() ? $money->text('عرض سعر') : 'SALES QUOTATION';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($quotation->quotation_number),
            'statusWatermark' => DocumentPdfSupport::watermark($quotation->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['customer'], $quotation->customer, $money, 'customer_code'),
            ],
            'lineColumns' => DocumentPdfSupport::commercialColumns(),
            'lines' => DocumentPdfSupport::commercialLines($quotation->lines, $money),
            'totals' => DocumentPdfSupport::commercialTotals($quotation, $money, $labels),
            'notes' => $money->text($quotation->notes),
        ], $options);
    }

    public function downloadFilename(SalesQuotation $quotation): string
    {
        return DocumentPdfOptions::sanitizeFilename($quotation->quotation_number ?? '', 'sales-quotation');
    }
}
