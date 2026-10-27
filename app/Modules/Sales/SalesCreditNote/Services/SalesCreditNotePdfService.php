<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Services;

use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class SalesCreditNotePdfService
{
    public function __construct(
        private readonly SalesCreditNoteService $salesCreditNoteService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(SalesCreditNote $note, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $note->status,
            [SalesCreditNoteStatus::Posted, SalesCreditNoteStatus::Reversed],
            'Only posted or reversed sales credit notes can be printed.',
            'SALES_CREDIT_NOTE_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $note = $this->salesCreditNoteService->find($note->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($note->credit_date), $money),
        ];
        if ($note->salesInvoice) {
            $meta[] = DocumentPdfSupport::metaRow($labels['invoice_number'], (string) $note->salesInvoice->invoice_number, $money);
        }
        if (! empty($note->reason)) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reason'], (string) $note->reason, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($note->currency, $note->exchange_rate, $money, $labels));

        $totals = DocumentPdfSupport::commercialTotals($note, $money, $labels);
        $totals[] = ['label' => $labels['applied_total'], 'value' => $money->money($note->applied_total ?? 0)];
        $totals[] = ['label' => $labels['refunded_total'], 'value' => $money->money($note->refunded_total ?? 0)];
        $totals[] = ['label' => $labels['open_credit'], 'value' => $money->money($note->open_credit ?? 0), 'emphasis' => true];

        $title = $options->isArabic() ? $money->text('إشعار دائن') : 'CREDIT NOTE';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($note->credit_note_number),
            'statusWatermark' => DocumentPdfSupport::watermark($note->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['customer'], $note->customer, $money, 'customer_code'),
            ],
            'lineColumns' => DocumentPdfSupport::commercialColumns(),
            'lines' => DocumentPdfSupport::commercialLines($note->lines, $money),
            'totals' => $totals,
            'notes' => $money->text($note->notes),
        ], $options);
    }

    public function downloadFilename(SalesCreditNote $note): string
    {
        return DocumentPdfOptions::sanitizeFilename($note->credit_note_number ?? '', 'sales-credit-note');
    }
}
