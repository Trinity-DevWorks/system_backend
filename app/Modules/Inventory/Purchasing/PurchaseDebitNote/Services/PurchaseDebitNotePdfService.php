<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Services;

use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteStatus;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class PurchaseDebitNotePdfService
{
    public function __construct(
        private readonly PurchaseDebitNoteService $purchaseDebitNoteService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(PurchaseDebitNote $note, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $note->status,
            [PurchaseDebitNoteStatus::Posted, PurchaseDebitNoteStatus::Reversed],
            'Only posted or reversed purchase debit notes can be printed.',
            'PURCHASE_DEBIT_NOTE_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $note = $this->purchaseDebitNoteService->find($note->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($note->debit_date), $money),
        ];
        if ($note->purchaseInvoice) {
            $meta[] = DocumentPdfSupport::metaRow($labels['invoice_number'], (string) $note->purchaseInvoice->invoice_number, $money);
        }
        if (! empty($note->reason)) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reason'], (string) $note->reason, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($note->currency, $note->exchange_rate, $money, $labels));

        $totals = DocumentPdfSupport::commercialTotals($note, $money, $labels);
        $totals[] = ['label' => $labels['applied_total'], 'value' => $money->money($note->applied_total ?? 0)];
        $totals[] = ['label' => $labels['refunded_total'], 'value' => $money->money($note->refunded_total ?? 0)];
        $totals[] = ['label' => $labels['open_credit'], 'value' => $money->money($note->open_credit ?? 0), 'emphasis' => true];

        $title = $options->isArabic() ? $money->text('إشعار مدين') : 'DEBIT NOTE';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($note->debit_note_number),
            'statusWatermark' => DocumentPdfSupport::watermark($note->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['supplier'], $note->supplier, $money, 'supplier_code'),
            ],
            'lineColumns' => DocumentPdfSupport::commercialColumns(),
            'lines' => DocumentPdfSupport::commercialLines($note->lines, $money),
            'totals' => $totals,
            'notes' => $money->text($note->notes),
        ], $options);
    }

    public function downloadFilename(PurchaseDebitNote $note): string
    {
        return DocumentPdfOptions::sanitizeFilename($note->debit_note_number ?? '', 'purchase-debit-note');
    }
}
