<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Models\Central\TenantCompanyWallet;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Support\WalletAddress;
use InvalidArgumentException;

/**
 * Publishes a tenant company wallet and matches another tenant's customer or supplier wallet to it.
 */
class TenantCompanyWalletService
{
    public function syncFromProfile(CompanyProfile $profile): void
    {
        $tenantId = tenant('id');
        if (! is_string($tenantId) || $tenantId === '') {
            return;
        }

        $this->sync(
            $tenantId,
            (string) $profile->company_name,
            $profile->wallet_address_anvil,
            $profile->wallet_type_anvil instanceof WalletType ? $profile->wallet_type_anvil->value : null,
            $profile->wallet_address_sepolia,
            $profile->wallet_type_sepolia instanceof WalletType ? $profile->wallet_type_sepolia->value : null,
        );
    }

    public function sync(
        string $tenantId,
        string $companyName,
        ?string $anvilAddress,
        ?string $anvilType,
        ?string $sepoliaAddress,
        ?string $sepoliaType,
    ): TenantCompanyWallet {
        $anvil = $this->nullableAddress($anvilAddress);
        $sepolia = $this->nullableAddress($sepoliaAddress);

        $this->releaseAddress('wallet_address_anvil', 'wallet_type_anvil', $anvil, $tenantId);
        $this->releaseAddress('wallet_address_sepolia', 'wallet_type_sepolia', $sepolia, $tenantId);

        return TenantCompanyWallet::query()->updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'company_name' => $companyName,
                'wallet_address_anvil' => $anvil,
                'wallet_type_anvil' => $anvil === null ? null : $anvilType,
                'wallet_address_sepolia' => $sepolia,
                'wallet_type_sepolia' => $sepolia === null ? null : $sepoliaType,
            ],
        );
    }

    /**
     * @return array{tenant_id: string, company_name: string}|null
     */
    public function knownTenant(?string $address): ?array
    {
        $match = $this->findByWallet($address);
        if ($match === null) {
            return null;
        }

        return [
            'tenant_id' => (string) $match->tenant_id,
            'company_name' => (string) $match->company_name,
        ];
    }

    public function findByWallet(?string $address): ?TenantCompanyWallet
    {
        $normalized = $this->nullableAddress($address);
        if ($normalized === null) {
            return null;
        }

        $query = TenantCompanyWallet::query()
            ->where(function ($inner) use ($normalized): void {
                $inner->where('wallet_address_anvil', $normalized)
                    ->orWhere('wallet_address_sepolia', $normalized);
            });

        $current = tenant('id');
        if (is_string($current) && $current !== '') {
            $query->where('tenant_id', '!=', $current);
        }

        return $query->first();
    }

    private function releaseAddress(string $addressColumn, string $typeColumn, ?string $address, string $tenantId): void
    {
        if ($address === null) {
            return;
        }

        TenantCompanyWallet::query()
            ->where('tenant_id', '!=', $tenantId)
            ->where($addressColumn, $address)
            ->update([
                $addressColumn => null,
                $typeColumn => null,
            ]);
    }

    private function nullableAddress(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        try {
            return WalletAddress::normalize($address);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
