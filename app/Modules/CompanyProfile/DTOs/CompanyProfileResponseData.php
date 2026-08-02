<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\DTOs;

use App\Models\Attachment;
use App\Modules\CompanyProfile\Models\CompanyProfile;

readonly class CompanyProfileResponseData
{
    /**
     * @param  array{id: string, file_name: string, mime_type: string}|null  $logo
     */
    public function __construct(
        public string $id,
        public string $companyName,
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

    public static function fromModel(CompanyProfile $profile): self
    {
        $profile->loadMissing('logoAttachment');

        return new self(
            id: $profile->id,
            companyName: $profile->company_name,
            legalName: $profile->legal_name,
            phone: $profile->phone,
            email: $profile->email,
            website: $profile->website,
            taxNumber: $profile->tax_number,
            registrationNumber: $profile->registration_number,
            address: $profile->address,
            logo: self::logoBrief($profile->logoAttachment),
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
            'company_name' => $this->companyName,
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

    /**
     * @return array{id: string, file_name: string, mime_type: string}|null
     */
    private static function logoBrief(?Attachment $attachment): ?array
    {
        if ($attachment === null) {
            return null;
        }

        return [
            'id' => (string) $attachment->id,
            'file_name' => $attachment->file_name,
            'mime_type' => $attachment->mime_type,
        ];
    }
}
