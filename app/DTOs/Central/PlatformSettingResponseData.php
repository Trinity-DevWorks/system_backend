<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\PlatformSetting;

readonly class PlatformSettingResponseData
{
    public function __construct(
        public int $id,
        public string $preferredLanguage,
        public string $timezone,
        public string $dateFormat,
        public string $numberFormat,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(PlatformSetting $settings): self
    {
        return new self(
            id: (int) $settings->id,
            preferredLanguage: $settings->preferred_language->value,
            timezone: (string) $settings->timezone,
            dateFormat: $settings->date_format->value,
            numberFormat: $settings->number_format->value,
            createdAt: (string) $settings->created_at,
            updatedAt: (string) $settings->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'preferred_language' => $this->preferredLanguage,
            'timezone' => $this->timezone,
            'date_format' => $this->dateFormat,
            'number_format' => $this->numberFormat,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
