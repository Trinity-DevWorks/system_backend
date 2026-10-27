<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Services;

use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class SalesOrderPdfService
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(SalesOrder $order, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $order->status,
            [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyInvoiced, SalesOrderStatus::Invoiced],
            'Only confirmed, partially invoiced, or invoiced sales orders can be printed.',
            'SALES_ORDER_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $order = $this->salesOrderService->find($order->id);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($order->order_date), $money),
        ];
        if ($order->requested_date) {
            $meta[] = DocumentPdfSupport::metaRow($labels['requested_date'], $money->date($order->requested_date), $money);
        }
        if ($order->salesQuotation) {
            $meta[] = DocumentPdfSupport::metaRow($labels['quotation_number'], (string) $order->salesQuotation->quotation_number, $money);
        }
        array_push($meta, ...DocumentPdfSupport::currencyMeta($order->currency, $order->exchange_rate, $money, $labels));

        $lines = DocumentPdfSupport::commercialLines($order->lines, $money);
        foreach ($order->lines->values() as $index => $line) {
            $lines[$index]['invoiced_qty'] = $money->quantity($this->invoicedQuantity($line));
            $lines[$index]['remaining_qty'] = $money->quantity($line->remainingQuantity());
        }

        $columns = DocumentPdfSupport::commercialColumns();
        array_push($columns, 'invoiced_qty', 'remaining_qty');

        $title = $options->isArabic() ? $money->text('أمر بيع') : 'SALES ORDER';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($order->order_number),
            'statusWatermark' => DocumentPdfSupport::watermark($order->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['customer'], $order->customer, $money, 'customer_code'),
                DocumentPdfSupport::warehouseBlock($labels['warehouse'], $order->warehouse, $money),
            ],
            'lineColumns' => $columns,
            'lines' => $lines,
            'totals' => DocumentPdfSupport::commercialTotals($order, $money, $labels),
            'notes' => $money->text($order->notes),
        ], $options);
    }

    public function downloadFilename(SalesOrder $order): string
    {
        return DocumentPdfOptions::sanitizeFilename($order->order_number ?? '', 'sales-order');
    }

    /**
     * Invoiced quantity expressed in the line's own UOM (stored in base units).
     */
    private function invoicedQuantity(SalesOrderLine $line): string
    {
        $factor = (string) $line->conversion_factor;
        if (! is_numeric($factor) || bccomp($factor, '0', 6) <= 0) {
            $factor = '1';
        }

        return bcdiv((string) ($line->invoiced_base_quantity ?? '0'), $factor, 6);
    }
}
