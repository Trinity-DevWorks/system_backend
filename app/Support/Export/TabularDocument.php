<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * Title, filters, headings, and rows shared by Excel and PDF table exports.
 */
final class TabularDocument
{
    /**
     * @param  list<string>  $headings
     * @param  list<list<string|null>>  $rows
     * @param  list<string>  $filters
     * @param  list<array{heading: string, lines: list<array{label: string, value: string}>}>  $summaries
     */
    public function __construct(
        public readonly string $title,
        public readonly string $filename,
        public readonly array $headings,
        public readonly array $rows,
        public readonly array $filters = [],
        public readonly array $summaries = [],
    ) {}

    public function headingRowNumber(): int
    {
        return count($this->filters) + 3;
    }

    /**
     * @return list<list<string|null>>
     */
    public function sheetRows(): array
    {
        $out = [[$this->title]];
        foreach ($this->filters as $line) {
            $out[] = [$line];
        }
        $out[] = [];
        $out[] = $this->headings;
        foreach ($this->rows as $row) {
            $out[] = $row;
        }
        if ($this->summaries !== []) {
            $out[] = [];
            foreach ($this->summaries as $block) {
                $out[] = [$block['heading']];
                foreach ($block['lines'] as $line) {
                    $out[] = [$line['label'], $line['value']];
                }
            }
        }

        return $out;
    }
}
