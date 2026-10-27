<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Enums\StockTransferStatus;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class StockTransferPdfService
{
    public function __construct(
        private readonly StockTransferService $stockTransferService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(StockTransfer $transfer, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $transfer->status,
            [StockTransferStatus::InTransit, StockTransferStatus::PartiallyReceived, StockTransferStatus::Received],
            'Only dispatched or received stock transfers can be printed.',
            'STOCK_TRANSFER_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $transfer = $this->stockTransferService->find($transfer->id);

        $date = $transfer->dispatched_at ?? $transfer->created_at;
        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($date), $money),
        ];

        $title = $options->isArabic() ? $money->text('تحويل مخزون') : 'STOCK TRANSFER';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($transfer->transfer_number),
            'statusWatermark' => DocumentPdfSupport::watermark($transfer->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::warehouseBlock($labels['from_warehouse'], $transfer->fromWarehouse, $money),
                DocumentPdfSupport::warehouseBlock($labels['to_warehouse'], $transfer->toWarehouse, $money),
            ],
            'lineColumns' => DocumentPdfSupport::quantityColumns(),
            'lines' => DocumentPdfSupport::quantityLines($transfer->lines, $money),
            'totals' => [],
            'notes' => $money->text($transfer->notes),
        ], $options);
    }

    public function downloadFilename(StockTransfer $transfer): string
    {
        return DocumentPdfOptions::sanitizeFilename($transfer->transfer_number ?? '', 'stock-transfer');
    }
}
