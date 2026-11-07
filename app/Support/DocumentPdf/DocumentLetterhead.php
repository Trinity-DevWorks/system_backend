<?php

declare(strict_types=1);

namespace App\Support\DocumentPdf;

use App\Modules\CompanyProfile\Services\CompanyProfileService;
use App\Support\Export\ArabicPdfText;
use Illuminate\Support\Facades\Storage;

final class DocumentLetterhead
{
    public function __construct(
        private readonly CompanyProfileService $companyProfileService,
    ) {}

    /**
     * @return array{
     *   company_name: string,
     *   legal_name: string|null,
     *   tax_number: string|null,
     *   phone: string|null,
     *   email: string|null,
     *   address: string|null,
     *   logo_data_uri: string|null
     * }
     */
    public function resolve(bool $arabic = false, ?DocumentPdfOptions $options = null): array
    {
        $profile = $this->companyProfileService->get();
        $name = trim((string) ($profile->company_name ?? ''));
        if ($name === '') {
            $name = (string) (tenant('name') ?? config('app.name', 'Company'));
        }

        $shape = static fn (?string $value): ?string => $value === null || $value === ''
            ? ($value === '' ? '' : null)
            : ($arabic ? ArabicPdfText::shape($value) : $value);

        return [
            'company_name' => $shape($name) ?? $name,
            'legal_name' => $shape($profile->legal_name),
            'tax_number' => ($options?->showTaxNumber ?? true) ? $shape($profile->tax_number) : null,
            'phone' => $shape($profile->phone),
            'email' => $shape($profile->email),
            'address' => ($options?->showAddress ?? true) ? $shape($profile->address) : null,
            'logo_data_uri' => ($options?->showLogo ?? true) ? $this->logoDataUri($profile->logoAttachment) : null,
        ];
    }

    private function logoDataUri(mixed $attachment): ?string
    {
        if ($attachment === null) {
            return null;
        }

        $disk = (string) ($attachment->disk ?? '');
        $path = (string) ($attachment->file_path ?? '');
        $mime = (string) ($attachment->mime_type ?? 'image/png');

        if ($disk === '' || $path === '' || ! Storage::disk($disk)->exists($path)) {
            return null;
        }

        try {
            $bytes = Storage::disk($disk)->get($path);
        } catch (\Throwable) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
