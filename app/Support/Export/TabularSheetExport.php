<?php

declare(strict_types=1);

namespace App\Support\Export;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class TabularSheetExport implements FromArray, ShouldAutoSize, WithEvents, WithStyles
{
    public function __construct(
        private readonly TabularDocument $document,
    ) {}

    /**
     * @return list<list<string|null>>
     */
    public function array(): array
    {
        return $this->document->sheetRows();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            $this->document->headingRowNumber() => ['font' => ['bold' => true]],
        ];
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                if (app()->getLocale() === 'ar') {
                    $event->sheet->getDelegate()->setRightToLeft(true);
                }
            },
        ];
    }
}
