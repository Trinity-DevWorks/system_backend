<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Customer\Models\Customer;
use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\Enums\WalletType;
use App\Modules\InvoiceProof\Exceptions\CompanySignerWalletException;
use Throwable;

/**
 * Buyer wallets must not be the company Safe or any of its owners. A buyer Safe
 * must not have the company Safe or a company Safe owner among its owners.
 * Company Safe addresses must not already be a customer wallet.
 */
final class CompanySafeSignerGuard
{
    public const CUSTOMER_ERROR_CODE = 'CUSTOMER_WALLET_IS_COMPANY_SAFE_OWNER';

    public const COMPANY_ERROR_CODE = 'COMPANY_SAFE_WALLET_USED_BY_CUSTOMER';

    public const WALLET_IS_CONTRACT_CODE = 'WALLET_ADDRESS_IS_CONTRACT';

    public const SAFE_NOT_FOUND_CODE = 'WALLET_SAFE_NOT_FOUND';

    public function __construct(
        private readonly CompanySafeOwnerLookup $companySafeOwnerLookup,
    ) {}

    /**
     * The declared type must match the active network: a personal wallet has no
     * contract code, a Safe has readable owners. Skipped when the chain is not configured.
     */
    public function assertWalletType(?string $wallet, ?WalletType $type): void
    {
        $normalized = WalletAddress::normalize($wallet);
        if ($normalized === null || $type === null || ! CompanySetting::current()->invoiceProofsEnabled()) {
            return;
        }

        try {
            $inspection = $this->companySafeOwnerLookup->inspect($normalized);
        } catch (Throwable $exception) {
            throw new CompanySignerWalletException(
                'INVOICE_PROOF_CHAIN_FAILED',
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Could not read the address from the chain.',
            );
        }

        if ($inspection === null || $inspection->matches($type)) {
            return;
        }

        if ($type === WalletType::Wallet) {
            throw new CompanySignerWalletException(
                self::WALLET_IS_CONTRACT_CODE,
                'This address is a Safe or smart contract, not a personal wallet. Choose Safe instead.',
            );
        }

        throw new CompanySignerWalletException(
            self::SAFE_NOT_FOUND_CODE,
            'No Safe exists at this address on the active network.',
        );
    }

    public function abortIfWalletTypeInvalid(?string $wallet, ?WalletType $type): void
    {
        try {
            $this->assertWalletType($wallet, $type);
        } catch (CompanySignerWalletException $exception) {
            $this->abortFromException($exception);
        }
    }

    public function assertCustomerWalletAllowed(?string $wallet): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            return;
        }

        $normalized = WalletAddress::normalize($wallet);
        if ($normalized === null) {
            return;
        }

        $safe = WalletAddress::normalize(CompanyProfile::singleton()->wallet_address);
        if ($safe === null) {
            return;
        }

        if (hash_equals($safe, $normalized)) {
            throw new CompanySignerWalletException(
                self::CUSTOMER_ERROR_CODE,
                'This wallet is the company Safe. Use a different buyer address.',
            );
        }

        $owners = $this->ownersOrFail($safe);

        foreach ($owners as $owner) {
            if (hash_equals($owner, $normalized)) {
                throw new CompanySignerWalletException(
                    self::CUSTOMER_ERROR_CODE,
                    'This wallet is a company Safe owner. Use a different buyer address.',
                );
            }
        }

        $companySigners = [$safe, ...$owners];
        foreach ($this->ownersOrFail($normalized) as $buyerOwner) {
            if (in_array($buyerOwner, $companySigners, true)) {
                throw new CompanySignerWalletException(
                    self::CUSTOMER_ERROR_CODE,
                    'This buyer Safe is controlled by a company signer. Use a different buyer address.',
                );
            }
        }
    }

    public function assertCompanySafeAllowed(?string $safeWallet): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            return;
        }

        $normalized = WalletAddress::normalize($safeWallet);
        if ($normalized === null) {
            return;
        }

        if ($this->customerUsesWallet($normalized)) {
            throw new CompanySignerWalletException(
                self::COMPANY_ERROR_CODE,
                'This wallet is already used by a customer. Use a different company Safe.',
            );
        }

        foreach ($this->ownersOrFail($normalized) as $owner) {
            if ($this->customerUsesWallet($owner)) {
                throw new CompanySignerWalletException(
                    self::COMPANY_ERROR_CODE,
                    'An owner of this Safe is already used as a customer wallet. Use a different company Safe.',
                );
            }
        }
    }

    /**
     * @return list<string> Empty for a plain wallet (no contract code).
     */
    private function ownersOrFail(string $address): array
    {
        try {
            return $this->companySafeOwnerLookup->ownersOf($address);
        } catch (Throwable $exception) {
            throw new CompanySignerWalletException(
                'INVOICE_PROOF_CHAIN_FAILED',
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Could not read Safe owners from the chain.',
            );
        }
    }

    private function customerUsesWallet(string $normalized): bool
    {
        return Customer::query()
            ->whereRaw('lower(wallet_address) = ?', [$normalized])
            ->exists();
    }

    public function abortIfCustomerWalletForbidden(?string $wallet): void
    {
        try {
            $this->assertCustomerWalletAllowed($wallet);
        } catch (CompanySignerWalletException $exception) {
            $this->abortFromException($exception);
        }
    }

    public function abortIfCompanySafeForbidden(?string $safeWallet): void
    {
        try {
            $this->assertCompanySafeAllowed($safeWallet);
        } catch (CompanySignerWalletException $exception) {
            $this->abortFromException($exception);
        }
    }

    private function abortFromException(CompanySignerWalletException $exception): never
    {
        $status = $exception->errorCode === 'INVOICE_PROOF_CHAIN_FAILED' ? 503 : 422;
        abort($status, $exception->getMessage(), [
            'X-Error-Code' => $exception->errorCode,
        ]);
    }
}
