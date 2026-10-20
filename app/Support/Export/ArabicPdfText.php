<?php

declare(strict_types=1);

namespace App\Support\Export;

use ArPHP\I18N\Arabic;

/**
 * Dompdf paints Arabic as isolated letters. Presentation forms are the joined shapes it can draw.
 */
final class ArabicPdfText
{
    public static function shape(?string $text): string
    {
        $text ??= '';
        if ($text === '' || preg_match('/\p{Arabic}/u', $text) !== 1) {
            return $text;
        }

        return (new Arabic)->utf8Glyphs($text, 1000, false, false);
    }
}
