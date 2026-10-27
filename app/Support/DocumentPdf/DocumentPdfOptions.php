<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use App\Modules\CompanySetting\Enums\PreferredLanguage;
use App\Modules\CompanySetting\Models\CompanySetting;

final class DocumentPdfOptions
{
    public function __construct(
        public readonly string $locale = 'en',
        public readonly string $paper = 'a4',
    ) {}

    public static function fromRequest(?string $locale): self
    {
        $normalized = strtolower(trim((string) $locale));
        if ($normalized === 'ar' || $normalized === 'en') {
            return new self(locale: $normalized);
        }

        $preferred = CompanySetting::current()->preferred_language ?? PreferredLanguage::En;

        return new self(locale: $preferred === PreferredLanguage::Ar ? 'ar' : 'en');
    }

    public function isArabic(): bool
    {
        return $this->locale === 'ar';
    }

    public static function sanitizeFilename(string $number, string $fallback = 'document'): string
    {
        $base = trim($number) !== '' ? $number : $fallback;

        return preg_replace('/[^\w\-]+/', '-', $base).'.pdf';
    }
}
