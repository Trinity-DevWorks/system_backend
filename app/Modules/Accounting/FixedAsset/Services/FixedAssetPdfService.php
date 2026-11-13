<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Services;

use App\Modules\Accounting\FixedAsset\Models\FixedAsset;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class FixedAssetPdfService
{
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(FixedAsset $asset, ?string $locale = null): PDF
    {
        $asset = $this->assets->find((string) $asset->id);
        $detail = $this->assets->detail($asset);
        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);
        $category = $asset->category?->name;

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($asset->in_service_date), $money),
            DocumentPdfSupport::metaRow($labels['reference'], $money->dash($category), $money),
        ];
        $lines = [];
        foreach ($detail['history'] as $row) {
            $lines[] = [
                'description' => $money->dash($row['month']),
                'line_total' => $money->money($row['amount']),
            ];
        }

        $title = $options->isArabic() ? $money->text('بطاقة أصل') : 'FIXED ASSET';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($asset->asset_number),
            'statusWatermark' => DocumentPdfSupport::watermark($asset->status),
            'meta' => $meta,
            'parties' => [],
            'lineColumns' => ['description', 'line_total'],
            'lines' => $lines,
            'totals' => [
                ['label' => $options->isArabic() ? $money->text('التكلفة') : 'Cost', 'value' => $money->money($asset->cost)],
                ['label' => $options->isArabic() ? $money->text('مجمع الاهتلاك') : 'Accumulated', 'value' => $money->money($asset->accumulated_depreciation)],
                ['label' => $options->isArabic() ? $money->text('القيمة الدفترية') : 'Book value', 'value' => $money->money($detail['book_value']), 'emphasis' => true],
            ],
            'notes' => $money->text($asset->name),
        ], $options);
    }

    public function downloadFilename(FixedAsset $asset): string
    {
        return DocumentPdfOptions::sanitizeFilename($asset->asset_number ?? '', 'fixed-asset');
    }
}
