<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * The scale import sheet: a plain grid (optional header row, then one row per item — no title,
 * no styling), because scale software reads cell A1 onwards.
 *
 * Codes (PLU, barcode, item code…) are written as text cells, as ERPs do, so leading zeros stay
 * and Excel does not show 9.5011E+12. Only the numeric fields are written as numbers.
 */
final class ScaleSheetExport extends DefaultValueBinder implements FromArray, WithCustomValueBinder
{
    /** Fields whose cells are numbers; everything else is text. */
    public const NUMERIC_FIELDS = ['price', 'vat_rate', 'shelf_life_days'];

    /**
     * @param  list<list<string>>  $rows
     * @param  list<string>  $fields  field per column, same order as the rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly array $fields,
        private readonly bool $hasHeader,
        private readonly string $decimalSeparator = '.',
    ) {}

    /**
     * @return list<list<string>>
     */
    public function array(): array
    {
        return $this->rows;
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        $isHeader = $this->hasHeader && $cell->getRow() === 1;
        $field = $this->fields[Coordinate::columnIndexFromString($cell->getColumn()) - 1] ?? null;
        $text = (string) ($value ?? '');

        if (! $isHeader && in_array($field, self::NUMERIC_FIELDS, true) && $text !== '') {
            $number = $this->decimalSeparator === ',' ? str_replace(',', '.', $text) : $text;
            if (is_numeric($number)) {
                $cell->setValueExplicit($number + 0, DataType::TYPE_NUMERIC);

                return true;
            }
        }

        $cell->setValueExplicit($text, DataType::TYPE_STRING);

        return true;
    }
}
