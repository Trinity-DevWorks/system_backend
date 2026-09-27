<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Support;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Customer\Models\Customer;
use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\Exceptions\CompanySignerWalletException;
use Throwable;

/**
 * Buyer wallets must not be the company Safe or any of its owners.
 * Company Safe addresses must not already be a customer wallet.
 */
final class CompanySafeSignerGuard
{
    public const CUSTOMER_ERROR_CODE = 'CUSTOMER_WALLET_IS_COMPANY_SAFE_OWNER';

    public const COMPANY_ERROR_CODE = 'COMPANY_SAFE_WALLET_USED_BY_CUSTOMER';

    public function __construct(
        private readonly CompanySafeOwnerLookup $companySafeOwnerLookup,
    ) {}

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

        try {
            $owners = $this->companySafeOwnerLookup->ownersOf($safe);
        } catch (Throwable $exception) {
            throw new CompanySignerWalletException(
                'INVOICE_PROOF_CHAIN_FAILED',
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Could not read company Safe owners from the chain.',
            );
        }

        foreach ($owners as $owner) {
            if (hash_equals($owner, $normalized)) {
                throw new CompanySignerWalletException(
                    self::CUSTOMER_ERROR_CODE,
                    'This wallet is a company Safe owner. Use a different buyer address.',
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

        try {
            $owners = $this->companySafeOwnerLookup->ownersOf($normalized);
        } catch (Throwable $exception) {
            throw new CompanySignerWalletException(
                'INVOICE_PROOF_CHAIN_FAILED',
                $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Could not read company Safe owners from the chain.',
            );
        }

        foreach ($owners as $owner) {
            if ($this->customerUsesWallet($owner)) {
                throw new CompanySignerWalletException(
                    self::COMPANY_ERROR_CODE,
                    'An owner of this Safe is already used as a customer wallet. Use a different company Safe.',
                );
            }
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
