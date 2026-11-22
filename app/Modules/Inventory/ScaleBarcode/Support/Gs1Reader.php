<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Support;

/**
 * Splits a GS1-128 / GS1 DataBar string into Application Identifiers.
 *
 * Accepts what scanners send ("]C1" / "]e0" prefix, FNC1 as the GS character \x1D) and the
 * human-readable form "(01)09501101530003(3103)001250" (handy for typing a test code).
 * Only the AIs a scale label uses are known; an unknown AI makes the whole string unreadable.
 */
final class Gs1Reader
{
    private const GS = "\x1D";

    /** Two-digit AIs with a fixed data length. */
    private const FIXED_2 = ['00' => 18, '01' => 14, '02' => 14, '11' => 6, '12' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6, '20' => 2];

    /** Variable-length AIs and their maximum data length. */
    private const VARIABLE = ['10' => 20, '21' => 20, '22' => 20, '30' => 8, '37' => 8, '90' => 30, '240' => 30, '241' => 30, '250' => 30];

    /**
     * @return array<string, string>|null AI => data, in the order printed
     */
    public static function read(string $raw): ?array
    {
        $code = preg_replace('/^\][A-Za-z][0-9A-Za-z]/', '', trim($raw)) ?? '';
        if ($code === '') {
            return null;
        }
        if (str_starts_with($code, '(')) {
            return self::readBracketed($code);
        }

        $fields = [];
        $pos = 0;
        $length = strlen($code);
        while ($pos < $length) {
            if ($code[$pos] === self::GS) {
                $pos++;

                continue;
            }
            $ai = self::identify(substr($code, $pos, 4));
            if ($ai === null) {
                return null;
            }
            [$name, $fixed, $max] = $ai;
            $pos += strlen($name);
            if ($fixed !== null) {
                $data = substr($code, $pos, $fixed);
                if (strlen($data) !== $fixed) {
                    return null;
                }
                $pos += $fixed;
            } else {
                $end = strpos($code, self::GS, $pos);
                $data = $end === false ? substr($code, $pos) : substr($code, $pos, $end - $pos);
                if ($data === '' || strlen($data) > $max) {
                    return null;
                }
                $pos += strlen($data);
            }
            $fields[$name] = $data;
        }

        return $fields === [] ? null : $fields;
    }

    /**
     * @return array<string, string>|null
     */
    private static function readBracketed(string $code): ?array
    {
        if (preg_match_all('/\((\d{2,4})\)([^(]+)/', $code, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }
        $fields = [];
        foreach ($matches as $match) {
            $data = str_replace(self::GS, '', trim($match[2]));
            $ai = self::identify($match[1].$data);
            if ($ai === null || $ai[0] !== $match[1]) {
                return null;
            }
            if ($ai[1] !== null && strlen($data) !== $ai[1]) {
                return null;
            }
            $fields[$match[1]] = $data;
        }

        return $fields;
    }

    /**
     * @return array{0: string, 1: int|null, 2: int}|null [AI, fixed length or null, max length]
     */
    private static function identify(string $head): ?array
    {
        $head = substr($head, 0, 4);
        $two = substr($head, 0, 2);
        $three = substr($head, 0, 3);
        if (isset(self::FIXED_2[$two])) {
            return [$two, self::FIXED_2[$two], self::FIXED_2[$two]];
        }
        if (isset(self::VARIABLE[$two])) {
            return [$two, null, self::VARIABLE[$two]];
        }
        if (isset(self::VARIABLE[$three])) {
            return [$three, null, self::VARIABLE[$three]];
        }
        if (strlen($head) === 4 && ctype_digit($head)) {
            // 310n–369n: measures (net weight kg = 310n, lb = 320n), 6 digits, n = decimals.
            if ((int) $three >= 310 && (int) $three <= 369) {
                return [$head, 6, 6];
            }
            // 392n price, 393n ISO currency + price; n = decimals.
            if ($three === '392') {
                return [$head, null, 15];
            }
            if ($three === '393') {
                return [$head, null, 18];
            }
        }

        return null;
    }
}
