<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderRules;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class PurchaseOrderPdfService
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(PurchaseOrder $order, ?string $locale = null): PDF
    {
        PurchaseOrderRules::assertPrintable($order);

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $order = $this->purchaseOrderService->find($order->id);
        $order->loadMissing([
            'supplier:id,supplier_code,name,email,phone,company_name',
            'warehouse:id,name,shortcut_name',
            'currency:id,code',
            'lines.item:id,item_code,name',
            'lines.itemUom.uom:id,code,name',
        ]);

        $meta = [
            ['label' => $labels['date'], 'value' => $money->date($order->order_date) ?: '—'],
        ];
        if ($order->expected_date) {
            $meta[] = ['label' => $labels['expected_date'], 'value' => $money->date($order->expected_date) ?: '—'];
        }
        if ($order->currency) {
            $meta[] = ['label' => $labels['currency'], 'value' => $money->dash($order->currency->code)];
        }

        $title = $options->isArabic() ? $money->text('أمر شراء') : 'PURCHASE ORDER';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($order->po_number),
            'statusWatermark' => DocumentPdfSupport::watermark($order->status),
            'meta' => $meta,
            'parties' => [
                DocumentPdfSupport::partyBlock($labels['supplier'], $order->supplier, $money, 'supplier_code'),
                [
                    'title' => $labels['deliver_to'],
                    'name' => $money->dash($order->warehouse?->name),
                    'lines' => array_values(array_filter([
                        $order->warehouse?->shortcut_name
                            ? $money->text((string) $order->warehouse->shortcut_name)
                            : null,
                    ])),
                ],
            ],
            'lineColumns' => DocumentPdfSupport::commercialColumns(),
            'lines' => DocumentPdfSupport::commercialLines($order->lines, $money),
            'totals' => DocumentPdfSupport::commercialTotals($order, $money, $labels),
            'notes' => $money->text($order->notes),
        ], $options);
    }

    public function downloadFilename(PurchaseOrder $order): string
    {
        return DocumentPdfOptions::sanitizeFilename($order->po_number ?? '', 'purchase-order');
    }
}
