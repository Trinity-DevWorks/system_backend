<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\DTOs;

use App\Modules\CompanyProfile\Http\Requests\UpdateCompanyProfileRequest;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\InvoiceProof\Support\BlockchainNetwork;
use App\Modules\InvoiceProof\Support\WalletAddress;

readonly class CompanyProfileData
{
    public function __construct(
        public string $companyName,
        public ?string $legalName,
        public ?string $phone,
        public ?string $email,
        public ?string $website,
        public ?string $taxNumber,
        public ?string $registrationNumber,
        public ?string $address,
        public ?string $walletAddressAnvil,
        public ?string $walletAddressSepolia,
    ) {}

    public static function fromUpdateRequest(UpdateCompanyProfileRequest $request, CompanyProfile $profile): self
    {
        $data = $request->validated();

        $anvil = array_key_exists('wallet_address_anvil', $data)
            ? WalletAddress::normalize($data['wallet_address_anvil'])
            : $profile->wallet_address_anvil;
        $sepolia = array_key_exists('wallet_address_sepolia', $data)
            ? WalletAddress::normalize($data['wallet_address_sepolia'])
            : $profile->wallet_address_sepolia;

        if (array_key_exists('wallet_address', $data)) {
            $active = WalletAddress::normalize($data['wallet_address']);
            if (BlockchainNetwork::isSepolia()) {
                $sepolia = $active;
            } else {
                $anvil = $active;
            }
        }

        return new self(
            companyName: $data['company_name'] ?? $profile->company_name,
            legalName: array_key_exists('legal_name', $data)
                ? self::nullableString($data['legal_name'])
                : $profile->legal_name,
            phone: array_key_exists('phone', $data)
                ? self::nullableString($data['phone'])
                : $profile->phone,
            email: array_key_exists('email', $data)
                ? self::nullableString($data['email'])
                : $profile->email,
            website: array_key_exists('website', $data)
                ? self::nullableString($data['website'])
                : $profile->website,
            taxNumber: array_key_exists('tax_number', $data)
                ? self::nullableString($data['tax_number'])
                : $profile->tax_number,
            registrationNumber: array_key_exists('registration_number', $data)
                ? self::nullableString($data['registration_number'])
                : $profile->registration_number,
            address: array_key_exists('address', $data)
                ? self::nullableString($data['address'])
                : $profile->address,
            walletAddressAnvil: $anvil,
            walletAddressSepolia: $sepolia,
        );
    }

    /**
     * @return array{
     *     company_name: string,
     *     legal_name: ?string,
     *     phone: ?string,
     *     email: ?string,
     *     website: ?string,
     *     tax_number: ?string,
     *     registration_number: ?string,
     *     address: ?string,
     *     wallet_address_anvil: ?string,
     *     wallet_address_sepolia: ?string
     * }
     */
    public function toArray(): array
    {
        return [
            'company_name' => $this->companyName,
            'legal_name' => $this->legalName,
            'phone' => $this->phone,
            'email' => $this->email,
            'website' => $this->website,
            'tax_number' => $this->taxNumber,
            'registration_number' => $this->registrationNumber,
            'address' => $this->address,
            'wallet_address_anvil' => $this->walletAddressAnvil,
            'wallet_address_sepolia' => $this->walletAddressSepolia,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
