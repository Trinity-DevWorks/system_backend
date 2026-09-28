<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\DTOs;

use App\Models\Attachment;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;

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
        public ?string $walletAddress,
        public ?string $walletAddressAnvil,
        public ?string $walletAddressSepolia,
        public ?string $walletType,
        public ?string $walletTypeAnvil,
        public ?string $walletTypeSepolia,
        public string $blockchainNetwork,
        public int $blockchainChainId,
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
            walletAddress: $profile->wallet_address,
            walletAddressAnvil: $profile->wallet_address_anvil,
            walletAddressSepolia: $profile->wallet_address_sepolia,
            walletType: $profile->wallet_type?->value,
            walletTypeAnvil: $profile->wallet_type_anvil?->value,
            walletTypeSepolia: $profile->wallet_type_sepolia?->value,
            blockchainNetwork: BlockchainNetwork::key(),
            blockchainChainId: (int) config('blockchain.chain_id'),
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
            'wallet_address' => $this->walletAddress,
            'wallet_address_anvil' => $this->walletAddressAnvil,
            'wallet_address_sepolia' => $this->walletAddressSepolia,
            'wallet_type' => $this->walletType,
            'wallet_type_anvil' => $this->walletTypeAnvil,
            'wallet_type_sepolia' => $this->walletTypeSepolia,
            'blockchain_network' => $this->blockchainNetwork,
            'blockchain_chain_id' => $this->blockchainChainId,
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
