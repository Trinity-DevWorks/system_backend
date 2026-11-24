<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * Writes rows as a delimited (CSV / TSV …) or fixed-width text file, in the encoding the receiving
 * software expects: UTF-8, UTF-8 with BOM (Excel), or Windows-1256 (older Arabic software).
 */
final class DelimitedFileWriter
{
    public const BOM = "\xEF\xBB\xBF";

    /**
     * @param  list<list<string>>  $rows  already formatted cells (strings)
     * @param  array{
     *   delimiter: string,
     *   quote: string,
     *   encoding: string,
     *   line_ending: string,
     *   widths?: list<int|null>,
     *   pad_chars?: list<string|null>,
     *   pad_sides?: list<string|null>,
     * }  $options  delimiter: "," ";" "tab" "|" or "fixed"; quote: always | needed | never;
     *              encoding: utf8 | utf8_bom | windows1256; line_ending: crlf | lf
     */
    public static function write(array $rows, array $options): string
    {
        $eol = ($options['line_ending'] ?? 'crlf') === 'lf' ? "\n" : "\r\n";
        $delimiter = $options['delimiter'] ?? ',';
        $lines = [];
        foreach ($rows as $row) {
            $lines[] = $delimiter === 'fixed'
                ? self::fixedLine($row, $options)
                : self::delimitedLine($row, $delimiter === 'tab' ? "\t" : $delimiter, $options['quote'] ?? 'needed');
        }
        $text = implode($eol, $lines).($lines === [] ? '' : $eol);

        return self::encode($text, $options['encoding'] ?? 'utf8');
    }

    /**
     * @param  list<string>  $row
     */
    private static function delimitedLine(array $row, string $delimiter, string $quote): string
    {
        $cells = [];
        foreach ($row as $cell) {
            $needs = $quote === 'always'
                || ($quote === 'needed' && (str_contains($cell, $delimiter) || str_contains($cell, '"') || str_contains($cell, "\n") || str_contains($cell, "\r")));
            if ($quote === 'never') {
                // Without quotes, a separator inside a value would shift the columns.
                $cell = str_replace([$delimiter, "\r", "\n"], ' ', $cell);
            }
            $cells[] = $needs ? '"'.str_replace('"', '""', $cell).'"' : $cell;
        }

        return implode($delimiter, $cells);
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, mixed>  $options
     */
    private static function fixedLine(array $row, array $options): string
    {
        $out = '';
        foreach ($row as $i => $cell) {
            $width = $options['widths'][$i] ?? null;
            $cell = str_replace(["\r", "\n"], ' ', $cell);
            if ($width === null || $width < 1) {
                $out .= $cell;

                continue;
            }
            $pad = (string) ($options['pad_chars'][$i] ?? ' ');
            $pad = $pad === '' ? ' ' : mb_substr($pad, 0, 1);
            $side = ($options['pad_sides'][$i] ?? 'right') === 'left' ? STR_PAD_LEFT : STR_PAD_RIGHT;
            $out .= self::mbPad(mb_substr($cell, 0, $width), $width, $pad, $side);
        }

        return $out;
    }

    private static function mbPad(string $text, int $width, string $pad, int $side): string
    {
        $missing = $width - mb_strlen($text);
        if ($missing <= 0) {
            return $text;
        }
        $fill = str_repeat($pad, $missing);

        return $side === STR_PAD_LEFT ? $fill.$text : $text.$fill;
    }

    private static function encode(string $text, string $encoding): string
    {
        if ($encoding === 'utf8_bom') {
            return self::BOM.$text;
        }
        if ($encoding !== 'windows1256') {
            return $text;
        }

        // mbstring has no Windows-1256; iconv does. Characters it can't hold fail: refuse rather than
        // export wrong names.
        $converted = self::toWindows1256($text);
        if ($converted === null) {
            $bad = self::unencodable($text);
            abort(422, 'These characters can\'t be saved in Windows-1256: '.$bad.'. Use UTF-8 instead.', [
                'X-Error-Code' => 'SCALE_EXPORT_ENCODING_UNSUPPORTED',
                'X-Error-Params' => (string) json_encode(['chars' => $bad]),
            ]);
        }

        return $converted;
    }

    private static function toWindows1256(string $text): ?string
    {
        $converted = @iconv('UTF-8', 'WINDOWS-1256', $text);

        return $converted === false ? null : $converted;
    }

    private static function unencodable(string $text): string
    {
        $bad = [];
        foreach (mb_str_split($text) as $char) {
            if (self::toWindows1256($char) === null) {
                $bad[$char] = true;
            }
            if (count($bad) >= 5) {
                break;
            }
        }

        return implode(' ', array_keys($bad));
    }
}
