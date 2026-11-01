<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Services;

use App\Modules\Sales\DeliveryNote\Enums\DeliveryNoteStatus;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Delivery note print: what was handed over, without prices.
 */
class DeliveryNotePdfService
{
    public function __construct(
        private readonly DeliveryNoteService $deliveryNoteService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function download(DeliveryNote $note, ?string $locale = null): Response
    {
        DocumentPdfSupport::assertPrintableStatus(
            $note->status,
            [DeliveryNoteStatus::Posted, DeliveryNoteStatus::Reversed],
            'Only posted or reversed delivery notes can be printed.',
            'DELIVERY_NOTE_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $note = $this->deliveryNoteService->find((string) $note->id);

        $meta = [DocumentPdfSupport::metaRow($labels['date'], $money->date($note->delivery_date), $money)];
        if ($note->salesOrder !== null) {
            $meta[] = DocumentPdfSupport::metaRow($labels['order_number'], (string) $note->salesOrder->order_number, $money);
        }

        $title = $options->isArabic() ? $money->text('إشعار تسليم') : 'DELIVERY NOTE';

        $pdf = $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($note->delivery_number),
            'statusWatermark' => DocumentPdfSupport::watermark($note->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['customer'], $note->customer, $money, 'customer_code'),
                DocumentPdfSupport::warehouseBlock($labels['warehouse'], $note->warehouse, $money),
            ],
            'lineColumns' => DocumentPdfSupport::quantityColumns(),
            'lines' => DocumentPdfSupport::quantityLines($note->lines, $money),
            'totals' => [],
            'notes' => $money->text($note->notes),
        ], $options);

        return $pdf->download(DocumentPdfOptions::sanitizeFilename($note->delivery_number ?? '', 'delivery-note'));
    }
}
