<?php

declare(strict_types=1);

namespace App\Support\Barcode;

use InvalidArgumentException;

/**
 * Draws EAN-13, UPC-A and Code 128 (incl. GS1-128 with FNC1) as an SVG, for PDFs (dompdf) and
 * screens. Pure PHP, no dependency. Widths are in "modules" (the narrowest bar).
 */
final class BarcodeSvg
{
    public const FNC1 = "\x1D";

    private const EAN_L = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];

    private const EAN_G = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];

    private const EAN_R = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];

    /** Which of the 6 left digits use the G set, by the first digit. */
    private const EAN_PARITY = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    /** Code 128 symbol widths (bar, space, bar, space, bar, space), values 0–106. */
    public const CODE128 = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const C128_START_B = 104;

    private const C128_START_C = 105;

    private const C128_CODE_B = 100;

    private const C128_CODE_C = 99;

    private const C128_FNC1 = 102;

    private const C128_STOP = 106;

    /**
     * Bar pattern ("1" = bar, "0" = space) of a 13-digit EAN (12 digits get their check digit added).
     */
    public static function ean13Modules(string $digits): string
    {
        if (preg_match('/^\d{12,13}$/', $digits) !== 1) {
            throw new InvalidArgumentException('EAN-13 needs 12 or 13 digits.');
        }
        if (strlen($digits) === 12) {
            $digits .= self::mod10($digits);
        }
        $parity = self::EAN_PARITY[(int) $digits[0]];
        $out = '101';
        for ($i = 1; $i <= 6; $i++) {
            $d = (int) $digits[$i];
            $out .= $parity[$i - 1] === 'G' ? self::EAN_G[$d] : self::EAN_L[$d];
        }
        $out .= '01010';
        for ($i = 7; $i <= 12; $i++) {
            $out .= self::EAN_R[(int) $digits[$i]];
        }

        return $out.'101';
    }

    /** UPC-A is an EAN-13 starting with 0. */
    public static function upcaModules(string $digits): string
    {
        if (preg_match('/^\d{11,12}$/', $digits) !== 1) {
            throw new InvalidArgumentException('UPC-A needs 11 or 12 digits.');
        }

        return self::ean13Modules('0'.$digits);
    }

    /**
     * Code 128 symbol values for $data (start, data, check, stop). "\x1D" (FNC1) at the start makes
     * it GS1-128; inside, it separates variable-length fields. Digit runs use set C, the rest set B.
     *
     * @return list<int>
     */
    public static function code128Values(string $data): array
    {
        if ($data === '' || preg_match('/^[\x1D\x20-\x7E]+$/', $data) !== 1) {
            throw new InvalidArgumentException('Code 128 here takes printable ASCII (and FNC1).');
        }
        $len = strlen($data);
        $digitRun = static function (int $from) use ($data, $len): int {
            $n = 0;
            while ($from + $n < $len && ctype_digit($data[$from + $n])) {
                $n++;
            }

            return $n;
        };

        $values = [];
        $pos = 0;
        $lead = $data[0] === self::FNC1 ? $digitRun(1) : $digitRun(0);
        $set = $lead >= 4 || ($lead === $len && $lead % 2 === 0 && $lead >= 2) ? 'C' : 'B';
        $values[] = $set === 'C' ? self::C128_START_C : self::C128_START_B;

        while ($pos < $len) {
            $char = $data[$pos];
            if ($char === self::FNC1) {
                $values[] = self::C128_FNC1;
                $pos++;

                continue;
            }
            $run = $digitRun($pos);
            if ($set === 'C') {
                if ($run >= 2) {
                    $values[] = (int) substr($data, $pos, 2);
                    $pos += 2;

                    continue;
                }
                $values[] = self::C128_CODE_B;
                $set = 'B';

                continue;
            }
            // In set B: switch to C for an even run of 4+ digits (or a run reaching the end).
            if ($run >= 4 && ($run % 2 === 0 || $pos + $run < $len)) {
                if ($run % 2 === 1) {
                    $values[] = ord($char) - 32;
                    $pos++;
                }
                $values[] = self::C128_CODE_C;
                $set = 'C';

                continue;
            }
            $values[] = ord($char) - 32;
            $pos++;
        }

        $sum = $values[0];
        foreach (array_slice($values, 1) as $i => $value) {
            $sum += $value * ($i + 1);
        }
        $values[] = $sum % 103;
        $values[] = self::C128_STOP;

        return $values;
    }

    public static function code128Modules(string $data): string
    {
        $out = '';
        foreach (self::code128Values($data) as $value) {
            $bar = true;
            foreach (str_split(self::CODE128[$value]) as $width) {
                $out .= str_repeat($bar ? '1' : '0', (int) $width);
                $bar = ! $bar;
            }
        }

        return $out;
    }

    /**
     * @param  string  $symbology  ean13 | upca | code128 | gs1_128
     */
    public static function modules(string $symbology, string $data): string
    {
        return match ($symbology) {
            'ean13' => self::ean13Modules($data),
            'upca' => self::upcaModules($data),
            'gs1_128' => self::code128Modules(str_starts_with($data, self::FNC1) ? $data : self::FNC1.$data),
            default => self::code128Modules($data),
        };
    }

    /**
     * SVG of the bars only (the human-readable text is printed by the label itself).
     * $quiet is the white margin on each side, in modules.
     */
    public static function svg(string $symbology, string $data, int $height = 50, int $quiet = 10): string
    {
        $modules = self::modules($symbology, $data);
        $width = strlen($modules) + 2 * $quiet;
        $rects = '';
        $x = 0;
        $length = strlen($modules);
        while ($x < $length) {
            if ($modules[$x] !== '1') {
                $x++;

                continue;
            }
            $start = $x;
            while ($x < $length && $modules[$x] === '1') {
                $x++;
            }
            $rects .= '<rect x="'.($start + $quiet).'" y="0" width="'.($x - $start).'" height="'.$height.'"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' '.$height.'" width="'.$width.'" height="'.$height.'" preserveAspectRatio="none">'
            .'<rect x="0" y="0" width="'.$width.'" height="'.$height.'" fill="#fff"/><g fill="#000">'.$rects.'</g></svg>';
    }

    public static function dataUri(string $symbology, string $data, int $height = 50): string
    {
        return 'data:image/svg+xml;base64,'.base64_encode(self::svg($symbology, $data, $height));
    }

    private static function mod10(string $body): string
    {
        $sum = 0;
        foreach (array_reverse(str_split($body)) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (string) ((10 - $sum % 10) % 10);
    }
}
