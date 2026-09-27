<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Services;

use App\Modules\CompanyProfile\DTOs\CompanyProfileData;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\InvoiceProof\Support\CompanySafeSignerGuard;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Support\TenantReferenceCache;

class CompanyProfileService
{
    public const CACHE_KEY = 'company_profile.singleton';

    public function __construct(
        private readonly CompanySafeSignerGuard $companySafeSignerGuard,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
    ) {}

    public function get(): CompanyProfile
    {
        $profile = TenantReferenceCache::rememberModel(
            self::CACHE_KEY,
            CompanyProfile::class,
            fn (): CompanyProfile => CompanyProfile::singleton()
        );

        return $profile->loadMissing('logoAttachment');
    }

    public function update(CompanyProfileData $data): CompanyProfile
    {
        $payload = $data->toArray();
        $this->companySafeSignerGuard->abortIfCompanySafeForbidden($payload['wallet_address_anvil'] ?? null);
        $this->companySafeSignerGuard->abortIfCompanySafeForbidden($payload['wallet_address_sepolia'] ?? null);

        $profile = CompanyProfile::singleton();
        $previousWallet = WalletAddress::normalize($profile->wallet_address);
        $profile->update($payload);
        $this->forgetCache();

        $profile = $profile->refresh()->load('logoAttachment');
        $nextWallet = WalletAddress::normalize($profile->wallet_address);
        if ($nextWallet !== null && $nextWallet !== $previousWallet) {
            $this->invoiceChainRegistrationService->dispatchSupplierPartySync();
        }

        return $profile;
    }

    public function forgetCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }
}
