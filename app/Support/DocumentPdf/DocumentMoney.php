<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use App\Modules\CompanySetting\Support\TenantDisplayFormat;
use App\Support\Export\ArabicPdfText;

final class DocumentMoney
{
    public function __construct(
        private readonly bool $arabic = false,
    ) {}

    public static function forOptions(DocumentPdfOptions $options): self
    {
        return new self($options->isArabic());
    }

    public function money(mixed $value): string
    {
        return $this->shape(TenantDisplayFormat::money($value));
    }

    public function quantity(mixed $value): string
    {
        return $this->shape(TenantDisplayFormat::quantity($value));
    }

    public function date(mixed $value): string
    {
        $formatted = TenantDisplayFormat::date($value);

        return $formatted === '' ? '' : $this->shape($formatted);
    }

    public function dateTime(mixed $value): string
    {
        $formatted = TenantDisplayFormat::dateTime($value);

        return $formatted === '' ? '' : $this->shape($formatted);
    }

    public function text(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return $this->shape($value);
    }

    public function dash(?string $value): string
    {
        $text = $this->text($value);

        return $text === '' ? '—' : $text;
    }

    private function shape(string $value): string
    {
        return $this->arabic ? ArabicPdfText::shape($value) : $value;
    }
}
