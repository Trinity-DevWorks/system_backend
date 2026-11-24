<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\PlatformBranding;
use App\Services\Central\PlatformBrandingService;

/**
 * Branding payload: product name and the logo cache-busting version.
 * Clients build the logo URL as `branding/logo?v={logo_version}` on the central API.
 */
readonly class PlatformBrandingResponseData
{
    public function __construct(
        public string $name,
        public bool $hasLogo,
        public ?int $logoVersion,
    ) {}

    public static function fromModel(PlatformBranding $branding): self
    {
        $name = trim((string) $branding->name);

        return new self(
            name: $name !== '' ? $name : PlatformBranding::DEFAULT_NAME,
            hasLogo: $branding->hasLogo(),
            logoVersion: PlatformBrandingService::logoVersion($branding),
        );
    }

    /**
     * @return array{name: string, has_logo: bool, logo_version: int|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'has_logo' => $this->hasLogo,
            'logo_version' => $this->logoVersion,
        ];
    }
}
