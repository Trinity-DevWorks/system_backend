<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Services;

use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Purchasing\Support\PurchaseOrderRules;
use Barryvdh\DomPDF\Facade\Pdf;

class PurchaseOrderPdfService
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService
    ) {}

    public function render(PurchaseOrder $order): \Barryvdh\DomPDF\PDF
    {
        PurchaseOrderRules::assertPrintable($order);

        $order = $this->purchaseOrderService->find($order->id);
        $order->loadMissing([
            'supplier:id,supplier_code,name,email,phone,company_name',
            'warehouse:id,name,shortcut_name',
            'lines.item:id,sku,name,item_code',
            'lines.itemUom.uom:id,code,name',
        ]);

        $lines = [];
        $total = null;

        foreach ($order->lines as $line) {
            $item = $line->item;
            $uomLabel = $line->itemUom?->uom?->code
                ?? $line->itemUom?->uom?->name
                ?? '—';
            $unitPrice = $line->unit_price !== null ? (string) $line->unit_price : null;
            $lineTotal = $unitPrice !== null
                ? bcmul($unitPrice, (string) $line->quantity, 4)
                : null;

            if ($lineTotal !== null) {
                $total = $total === null
                    ? $lineTotal
                    : bcadd($total, $lineTotal, 4);
            }

            $lines[] = [
                'name' => $item?->name ?? '—',
                'sku' => $item?->item_code ?: ($item?->sku ?? '—'),
                'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'),
                'uom' => $uomLabel,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        $companyName = (string) (tenant('name') ?? config('app.name', 'Company'));

        return Pdf::loadView('purchasing.purchase-order', [
            'companyName' => $companyName,
            'poNumber' => $order->po_number ?? 'Purchase Order',
            'orderDate' => $order->order_date?->format('Y-m-d') ?? '—',
            'expectedDate' => $order->expected_date?->format('Y-m-d'),
            'supplierName' => $order->supplier?->company_name ?: ($order->supplier?->name ?? '—'),
            'supplierCode' => $order->supplier?->supplier_code,
            'supplierEmail' => $order->supplier?->email,
            'supplierPhone' => $order->supplier?->phone,
            'warehouseName' => $order->warehouse?->name ?? '—',
            'warehouseShortcut' => $order->warehouse?->shortcut_name,
            'lines' => $lines,
            'totalAmount' => $total,
            'notes' => $order->notes,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4');
    }

    public function downloadFilename(PurchaseOrder $order): string
    {
        $number = $order->po_number ?? 'purchase-order';

        return preg_replace('/[^\w\-]+/', '-', $number).'.pdf';
    }
}
