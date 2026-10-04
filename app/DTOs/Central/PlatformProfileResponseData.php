<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Models\Central\PlatformProfile;
use App\Services\Central\PlatformProfileService;

readonly class PlatformProfileResponseData
{
    /**
     * @param  array{mime_type: string|null, version: int|null, updated_at: string|null}|null  $logo
     */
    public function __construct(
        public int $id,
        public string $name,
        public ?string $legalName,
        public ?string $phone,
        public ?string $email,
        public ?string $website,
        public ?string $taxNumber,
        public ?string $registrationNumber,
        public ?string $address,
        public ?array $logo,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(PlatformProfile $profile): self
    {
        return new self(
            id: (int) $profile->id,
            name: (string) $profile->name,
            legalName: self::nullableString($profile->legal_name),
            phone: self::nullableString($profile->phone),
            email: self::nullableString($profile->email),
            website: self::nullableString($profile->website),
            taxNumber: self::nullableString($profile->tax_number),
            registrationNumber: self::nullableString($profile->registration_number),
            address: self::nullableString($profile->address),
            logo: $profile->hasLogo()
                ? [
                    'mime_type' => self::nullableString($profile->logo_mime_type),
                    'version' => PlatformProfileService::logoVersion($profile),
                    'updated_at' => $profile->logo_updated_at !== null ? (string) $profile->logo_updated_at : null,
                ]
                : null,
            createdAt: (string) $profile->created_at,
            updatedAt: (string) $profile->updated_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'tax_number' => $this->taxNumber,
            'registration_number' => $this->registrationNumber,
            'address' => $this->address,
            'logo' => $this->logo,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
