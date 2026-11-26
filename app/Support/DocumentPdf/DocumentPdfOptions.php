<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use App\Models\PrintSetting;
use App\Modules\CompanySetting\Enums\PreferredLanguage;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Support\DocumentType;

final class DocumentPdfOptions
{
    public function __construct(
        public readonly string $locale = 'en',
        public readonly string $paper = 'a4',
        /** "document" (full page) or "receipt" (thermal roll). */
        public readonly string $template = 'document',
        /** Thermal roll width in mm (receipt template only): 80 or 58. */
        public readonly int $rollWidthMm = 80,
        public readonly bool $showLogo = true,
        public readonly bool $showTaxNumber = true,
        public readonly bool $showAddress = true,
        public readonly bool $showSignatures = true,
        public readonly ?string $footerText = null,
        public readonly ?string $termsText = null,
    ) {}

    /**
     * Settings come from the document type's own print settings, else the company default.
     * A type set to a thermal roll prints as a receipt unless a full page is asked for.
     *
     * @param  bool  $allowReceipt  only documents with a receipt layout (sales invoice, customer receipt) pass true
     */
    public static function fromRequest(?string $locale, ?string $template = null, bool $allowReceipt = false, ?DocumentType $documentType = null): self
    {
        $normalized = strtolower(trim((string) $locale));
        if ($normalized !== 'ar' && $normalized !== 'en') {
            $preferred = CompanySetting::current()->preferred_language ?? PreferredLanguage::En;
            $normalized = $preferred === PreferredLanguage::Ar ? 'ar' : 'en';
        }

        $print = PrintSetting::current($documentType);
        $receipt = $allowReceipt && ($template === 'receipt' || ($template === null && $print->isRoll()));
        // A roll type asked for as a full page uses the company default page size.
        $pagePaper = in_array($print->paper, PrintSetting::PAGE_PAPERS, true) ? $print->paper : PrintSetting::current()->paper;

        return new self(
            locale: $normalized,
            paper: in_array($pagePaper, PrintSetting::PAGE_PAPERS, true) ? $pagePaper : 'a4',
            template: $receipt ? 'receipt' : 'document',
            rollWidthMm: $print->paper === 'roll58' ? 58 : 80,
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
