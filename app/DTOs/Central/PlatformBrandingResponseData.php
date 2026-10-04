<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\PlatformProfile;
use App\Models\Central\PlatformSetting;
use App\Services\Central\PlatformProfileService;

/**
 * Public branding payload (no auth): product name, the logo cache-busting version, and the
 * platform regional formats used by the central console.
 * Clients build the logo URL as `branding/logo?v={logo_version}` on the central API.
 */
readonly class PlatformBrandingResponseData
{
    /**
     * @param  array{preferred_language: string, timezone: string, date_format: string, number_format: string}  $regional
     */
    public function __construct(
        public string $name,
        public bool $hasLogo,
        public ?int $logoVersion,
        public array $regional,
    ) {}

    public static function fromModels(PlatformProfile $profile, PlatformSetting $settings): self
    {
        $name = trim((string) $profile->name);

        return new self(
            name: $name !== '' ? $name : PlatformProfile::DEFAULT_NAME,
            hasLogo: $profile->hasLogo(),
            logoVersion: PlatformProfileService::logoVersion($profile),
            regional: [
                'preferred_language' => $settings->preferred_language->value,
                'timezone' => (string) $settings->timezone,
                'date_format' => $settings->date_format->value,
                'number_format' => $settings->number_format->value,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'has_logo' => $this->hasLogo,
            'logo_version' => $this->logoVersion,
            'regional' => $this->regional,
        ];
    }
}
