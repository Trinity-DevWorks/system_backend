<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Services;

use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostStatus;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCost;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Symfony\Component\HttpFoundation\Response;

/**
 * Landed cost print: the charges used, and per receipt line the added cost and the landed unit cost.
 */
class LandedCostPdfService
{
    public function __construct(
        private readonly LandedCostService $service,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function download(LandedCost $document, ?string $locale = null): Response
    {
        DocumentPdfSupport::assertPrintableStatus(
            $document->status,
            [LandedCostStatus::Posted, LandedCostStatus::Reversed],
            'Only posted or reversed landed costs can be printed.',
            'LANDED_COST_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);
        $data = $this->service->toArray($this->service->find((string) $document->id));

        $meta = [DocumentPdfSupport::metaRow($labels['date'], $money->date($data['cost_date']), $money)];
        if (! empty($data['reference'])) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $data['reference'], $money);
        }
        $receiptNumbers = implode(', ', array_filter(array_map(fn (array $r): ?string => $r['number'], $data['receipts'])));
        if ($receiptNumbers !== '') {
            $meta[] = DocumentPdfSupport::metaRow($labels['grn_number'], $receiptNumbers, $money);
        }

        $lines = array_map(fn (array $line): array => [
            'code' => $money->text((string) ($line['item_code'] ?? '')),
            'description' => $money->text(trim(($line['item_name'] ?? '').' · '.($line['receipt_number'] ?? ''))),
            'qty' => $money->quantity($line['received_quantity']),
            'unit_cost' => $money->money($line['landed_unit_cost']),
            'line_total' => $money->money($line['added_amount']),
        ], $data['lines']);

        $charges = array_map(fn (array $c): array => [
            'document_number' => $money->text(trim(($c['document_number'] ?? '').' · '.($c['description'] ?? ''))),
            'amount' => $money->money($c['amount']),
        ], $data['charges']);

        $title = $options->isArabic() ? $money->text('تكاليف الاستيراد') : 'LANDED COST';

        $pdf = $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($data['landed_cost_number']),
            'statusWatermark' => DocumentPdfSupport::watermark($document->status),
            'meta' => $meta,
            'parties' => [],
            'lineColumns' => ['code', 'description', 'qty', 'unit_cost', 'line_total'],
            'lines' => $lines,
            'allocations' => $charges,
            'totals' => [
                ['label' => $labels['grand_total'], 'value' => $money->money($data['total_amount']), 'emphasis' => true],
            ],
            'notes' => $money->text($data['notes']),
        ], $options);

        return $pdf->download(DocumentPdfOptions::sanitizeFilename($data['landed_cost_number'] ?? '', 'landed-cost'));
    }
}
