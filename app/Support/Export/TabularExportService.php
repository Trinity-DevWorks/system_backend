<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

final class TabularExportService
{
    public function download(TabularDocument $document, string $format): Response
    {
        return $format === 'pdf'
            ? $this->pdf($document)
            : $this->excel($document);
    }

    private function excel(TabularDocument $document): Response
    {
        return Excel::download(new TabularSheetExport($document), $document->filename.'.xlsx');
    }

    private function pdf(TabularDocument $document): Response
    {
        $arabic = app()->getLocale() === 'ar';
        $forPdf = $arabic ? $this->arabicPdfDocument($document) : $document;
        $companyName = (string) (tenant('name') ?? config('app.name', 'Company'));
        $generatedAt = TenantDisplayFormat::dateTime(now()) ?: now()->format('Y-m-d H:i');

        $pdf = Pdf::loadView('exports.tabular', [
            'document' => $forPdf,
            'arabic' => $arabic,
            'dir' => 'ltr',
            'companyName' => $arabic ? ArabicPdfText::shape($companyName) : $companyName,
            'generatedAt' => $arabic ? ArabicPdfText::shape($generatedAt) : $generatedAt,
        ])->setPaper('a4', 'landscape');

        return $pdf->download($document->filename.'.pdf');
    }

    /**
     * Dompdf cannot join Arabic letters. Shape them, then lay the columns out from the right.
     */
    private function arabicPdfDocument(TabularDocument $document): TabularDocument
    {
        $shape = static fn (?string $value): string => ArabicPdfText::shape($value);

        $headings = array_reverse(array_map($shape, $document->headings));
        $rows = [];
        foreach ($document->rows as $row) {
            $cells = [];
            foreach ($row as $cell) {
                $cells[] = $shape($cell === null ? '' : (string) $cell);
            }
            $rows[] = array_reverse($cells);
        }

        $summaries = [];
        foreach ($document->summaries as $block) {
            $lines = [];
            foreach ($block['lines'] as $line) {
                $lines[] = [
                    'label' => $shape($line['label']),
                    'value' => $shape($line['value']),
                ];
            }
            $summaries[] = [
                'heading' => $shape($block['heading']),
                'lines' => $lines,
            ];
        }

        return new TabularDocument(
            title: $shape($document->title),
            filename: $document->filename,
            headings: $headings,
            rows: $rows,
            filters: array_map($shape, $document->filters),
            summaries: $summaries,
        );
    }
}
