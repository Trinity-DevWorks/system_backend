<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use App\Models\PrintSetting;
use App\Modules\CompanySetting\Enums\PreferredLanguage;
use App\Modules\CompanySetting\Models\CompanySetting;

final class DocumentPdfOptions
{
    public function __construct(
        public readonly string $locale = 'en',
        public readonly string $paper = 'a4',
        /** "document" (full page) or "receipt" (80mm roll). */
        public readonly string $template = 'document',
        public readonly bool $showLogo = true,
        public readonly bool $showTaxNumber = true,
        public readonly bool $showAddress = true,
        public readonly bool $showSignatures = true,
        public readonly ?string $footerText = null,
        public readonly ?string $termsText = null,
    ) {}

    /**
     * @param  bool  $allowReceipt  only documents with a receipt layout (sales invoice, customer receipt) pass true
     */
    public static function fromRequest(?string $locale, ?string $template = null, bool $allowReceipt = false): self
    {
        $normalized = strtolower(trim((string) $locale));
        if ($normalized !== 'ar' && $normalized !== 'en') {
            $preferred = CompanySetting::current()->preferred_language ?? PreferredLanguage::En;
            $normalized = $preferred === PreferredLanguage::Ar ? 'ar' : 'en';
        }

        $print = PrintSetting::current();
        $paper = in_array($print->paper, PrintSetting::PAPERS, true) ? $print->paper : 'a4';
        $template = $allowReceipt && $template === 'receipt' ? 'receipt' : 'document';

        return new self(
            locale: $normalized,
            paper: $paper,
            template: $template,
            showLogo: (bool) $print->show_logo,
            showTaxNumber: (bool) $print->show_tax_number,
            showAddress: (bool) $print->show_address,
            showSignatures: (bool) $print->show_signatures,
            footerText: self::blank($print->footer_text),
            termsText: self::blank($print->terms_text),
        );
    }

    public function isReceipt(): bool
    {
        return $this->template === 'receipt';
    }

    private static function blank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
