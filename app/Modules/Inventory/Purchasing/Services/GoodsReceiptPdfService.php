<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class GoodsReceiptPdfService
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceiptService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(GoodsReceipt $receipt, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $receipt->status,
            [GoodsReceiptStatus::Posted, GoodsReceiptStatus::Reversed],
            'Only posted or reversed goods receipts can be printed.',
            'GOODS_RECEIPT_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $receipt = $this->goodsReceiptService->find($receipt->id);
        $receipt->loadMissing(['currency:id,code']);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($receipt->received_date), $money),
        ];
        if ($receipt->purchaseOrder) {
            $meta[] = DocumentPdfSupport::metaRow($labels['po_number'], (string) $receipt->purchaseOrder->po_number, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($receipt->currency, $receipt->exchange_rate, $money, $labels));

        $supplier = $receipt->supplier ?? $receipt->purchaseOrder?->supplier;

        $title = $options->isArabic() ? $money->text('إيصال استلام بضاعة') : 'GOODS RECEIPT';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($receipt->grn_number),
            'statusWatermark' => DocumentPdfSupport::watermark($receipt->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['supplier'], $supplier, $money, 'supplier_code'),
                DocumentPdfSupport::warehouseBlock($labels['warehouse'], $receipt->warehouse, $money),
            ],
            'lineColumns' => DocumentPdfSupport::quantityColumns(true),
            'lines' => DocumentPdfSupport::quantityLines($receipt->lines, $money, true),
            'totals' => [],
            'notes' => $money->text($receipt->notes),
        ], $options);
    }

    public function downloadFilename(GoodsReceipt $receipt): string
    {
        return DocumentPdfOptions::sanitizeFilename($receipt->grn_number ?? '', 'goods-receipt');
    }
}
