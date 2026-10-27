<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Barryvdh\DomPDF\PDF;

class JournalEntryPdfService
{
    public function __construct(
        private readonly JournalEntryService $journalEntryService,
        private readonly DocumentPdfRenderer $renderer,
    ) {}

    public function render(JournalEntry $entry, ?string $locale = null): PDF
    {
        DocumentPdfSupport::assertPrintableStatus(
            $entry->status,
            [JournalEntryStatus::Posted, JournalEntryStatus::Reversed],
            'Only posted or reversed journal entries can be printed.',
            'JOURNAL_ENTRY_PDF_NOT_ALLOWED',
        );

        $options = DocumentPdfOptions::fromRequest($locale);
        $money = DocumentMoney::forOptions($options);
        $labels = $this->renderer->labelsFor($options);

        $entry = $this->journalEntryService->load($entry);

        $meta = [
            DocumentPdfSupport::metaRow($labels['date'], $money->date($entry->entry_date), $money),
            DocumentPdfSupport::metaRow($labels['source'], (string) $entry->source_type, $money),
        ];
        if ($entry->reversalOf) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $entry->reversalOf->entry_number, $money);
        } elseif ($entry->reversal) {
            $meta[] = DocumentPdfSupport::metaRow($labels['reference'], (string) $entry->reversal->entry_number, $money);
        }

        $lines = [];
        foreach ($entry->lines as $line) {
            $account = $line->account;
            $accountName = $options->isArabic()
                ? ($account?->name_ar ?: $account?->name)
                : $account?->name;
            $accountLabel = trim(($account?->code ?? '').' - '.($accountName ?? ''), ' -');

            $lines[] = [
                'account' => $money->dash($accountLabel !== '' ? $accountLabel : null),
                'description' => $money->dash($line->description !== null ? (string) $line->description : null),
                'debit' => (float) $line->debit != 0.0 ? $money->money($line->debit) : '',
                'credit' => (float) $line->credit != 0.0 ? $money->money($line->credit) : '',
            ];
        }

        $totals = [
            ['label' => $labels['total_debit'], 'value' => $money->money($entry->total_debit ?? 0), 'emphasis' => true],
            ['label' => $labels['total_credit'], 'value' => $money->money($entry->total_credit ?? 0), 'emphasis' => true],
        ];

        $title = $options->isArabic() ? $money->text('قيد يومية') : 'JOURNAL ENTRY';

        return $this->renderer->render('document-pdf.document', [
            'documentTitle' => $title,
            'documentNumber' => $money->dash($entry->entry_number),
            'statusWatermark' => DocumentPdfSupport::watermark($entry->status),
            'meta' => $meta,
            'parties' => [],
            'lineColumns' => ['account', 'description', 'debit', 'credit'],
            'lines' => $lines,
            'totals' => $totals,
            'notes' => $money->text($entry->description),
        ], $options);
    }

    public function downloadFilename(JournalEntry $entry): string
    {
        return DocumentPdfOptions::sanitizeFilename($entry->entry_number ?? '', 'journal-entry');
    }
}
