<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceVerifierData;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Jobs\SyncInvoiceVerifierOnChainJob;
use App\Modules\InvoiceProof\Models\InvoiceVerifier;
use App\Modules\InvoiceProof\Support\CompanySafeSignerGuard;
use App\Modules\InvoiceProof\Support\WalletAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Company-managed verifiers (banks, auditors, tax authorities). The ERP row is the
 * address book; the registrar writes the permission on InvoiceRegistry for the
 * company Safe so the verifier can attest from its own wallet or Safe.
 */
class InvoiceVerifierService
{
    public function __construct(
        private readonly InvoiceRegistryGateway $invoiceRegistryGateway,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
        private readonly CompanySafeOwnerLookup $companySafeOwnerLookup,
        private readonly CompanySafeSignerGuard $companySafeSignerGuard,
    ) {}

    /**
     * @return Collection<int, InvoiceVerifier>
     */
    public function list(): Collection
    {
        $this->ensureEnabled();

        return InvoiceVerifier::query()->orderBy('name')->get();
    }

    public function create(InvoiceVerifierData $data): InvoiceVerifier
    {
        $this->ensureEnabled();
        $this->companySafeSignerGuard->abortIfWalletTypeInvalid($data->walletAddress, $data->walletType);
        $this->assertWalletAllowed((string) $data->walletAddress);

        $verifier = InvoiceVerifier::query()->create([
            'name' => $data->name,
            'role' => $data->role,
            'wallet_address' => $data->walletAddress,
            'wallet_type' => $data->walletType,
            'notes' => $data->notes,
            'chain_status' => InvoiceVerifierChainStatus::Pending,
        ]);

        $this->dispatchSync($verifier);

        return $verifier;
    }

    public function update(InvoiceVerifier $verifier, InvoiceVerifierData $data): InvoiceVerifier
    {
        $this->ensureEnabled();
        $this->abortIfRemoving($verifier);

        $roleChanged = $verifier->role !== $data->role;
        $verifier->update([
            'name' => $data->name,
            'role' => $data->role,
            'notes' => $data->notes,
            ...($roleChanged ? ['chain_status' => InvoiceVerifierChainStatus::Pending, 'chain_error' => null] : []),
        ]);

        if ($roleChanged) {
            $this->dispatchSync($verifier);
        }

        return $verifier->refresh();
    }

    public function delete(InvoiceVerifier $verifier): void
    {
        $this->ensureEnabled();

        if ($verifier->chain_company_wallet === null || ! $this->invoiceChainRegistrationService->isConfigured()) {
            $verifier->delete();

            return;
        }

        $verifier->update(['chain_status' => InvoiceVerifierChainStatus::Removing, 'chain_error' => null]);
        $this->dispatchSync($verifier);
    }

    public function resync(InvoiceVerifier $verifier): InvoiceVerifier
    {
        $this->ensureEnabled();

        if ($verifier->chain_status !== InvoiceVerifierChainStatus::Removing) {
            $verifier->update(['chain_status' => InvoiceVerifierChainStatus::Pending, 'chain_error' => null]);
        }
        $this->dispatchSync($verifier);

        return $verifier->refresh();
    }

    /**
     * The company Safe changed: list every verifier again under the new Safe.
     */
    public function dispatchCompanyResync(): void
    {
        if (! $this->invoiceChainRegistrationService->isConfigured()) {
            return;
        }

        InvoiceVerifier::query()
            ->where('chain_status', '!=', InvoiceVerifierChainStatus::Removing)
            ->orderBy('id')
            ->each(function (InvoiceVerifier $verifier): void {
                $verifier->update(['chain_status' => InvoiceVerifierChainStatus::Pending, 'chain_error' => null]);
                $this->dispatchSync($verifier);
            });
    }

    /**
     * Queue handler. Throws on chain failure so the job retries.
     */
    public function syncOnChain(string $verifierId): void
    {
        if (! $this->invoiceChainRegistrationService->isConfigured()) {
            return;
        }

        $verifier = InvoiceVerifier::query()->find($verifierId);
        if ($verifier === null) {
            return;
        }

        try {
            if ($verifier->chain_status === InvoiceVerifierChainStatus::Removing) {
                if ($verifier->chain_company_wallet !== null) {
                    $this->invoiceRegistryGateway->setVerifier(
                        $verifier->chain_company_wallet,
                        $verifier->wallet_address,
                        InvoiceVerifierRole::CHAIN_NONE,
                    );
                }
                $verifier->delete();

                return;
            }

            $company = WalletAddress::normalize(CompanyProfile::singleton()->wallet_address);
            if ($company === null) {
                $verifier->update([
                    'chain_status' => InvoiceVerifierChainStatus::Failed,
                    'chain_error' => 'Company Safe wallet is not set.',
                ]);

                return;
            }

            $receipt = $this->invoiceRegistryGateway->setVerifier(
                $company,
                $verifier->wallet_address,
                $verifier->role->toChain(),
            );

            $verifier->update([
                'chain_status' => InvoiceVerifierChainStatus::Active,
                'chain_company_wallet' => $company,
                'chain_tx_hash' => $receipt->txHash,
                'chain_error' => null,
                'chain_synced_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $verifier->update([
                'chain_status' => $verifier->chain_status === InvoiceVerifierChainStatus::Removing
                    ? InvoiceVerifierChainStatus::Removing
                    : InvoiceVerifierChainStatus::Failed,
                'chain_error' => Str::limit($exception->getMessage(), 500),
            ]);

            throw $exception;
        }
    }

    private function dispatchSync(InvoiceVerifier $verifier): void
    {
        if (! $this->invoiceChainRegistrationService->isConfigured()) {
            return;
        }

        $tenantId = tenant('id');
        SyncInvoiceVerifierOnChainJob::dispatch(is_string($tenantId) ? $tenantId : null, (string) $verifier->id);
    }

    private function ensureEnabled(): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }
    }

    private function abortIfRemoving(InvoiceVerifier $verifier): void
    {
        if ($verifier->chain_status === InvoiceVerifierChainStatus::Removing) {
            throw ValidationException::withMessages([
                'name' => 'This verifier is being removed.',
            ]);
        }
    }

    /**
     * A company signer attesting its own invoices would defeat third-party verification.
     */
    private function assertWalletAllowed(string $wallet): void
    {
        $profile = CompanyProfile::singleton();
        $safes = array_filter([
            WalletAddress::normalize($profile->wallet_address_anvil),
            WalletAddress::normalize($profile->wallet_address_sepolia),
        ]);

        foreach ($safes as $safe) {
            if (hash_equals($safe, $wallet)) {
                throw ValidationException::withMessages([
                    'wallet_address' => 'This wallet is the company Safe. Use the verifier\'s own wallet.',
                ]);
            }
        }

        $activeSafe = WalletAddress::normalize($profile->wallet_address);
        if ($activeSafe === null) {
            return;
        }

        try {
            $owners = $this->companySafeOwnerLookup->ownersOf($activeSafe);
            $verifierOwners = $this->companySafeOwnerLookup->ownersOf($wallet);
        } catch (Throwable) {
            return;
        }

        foreach ($owners as $owner) {
            if (hash_equals(strtolower($owner), $wallet)) {
                throw ValidationException::withMessages([
                    'wallet_address' => 'This wallet is a company Safe owner. Use the verifier\'s own wallet.',
                ]);
            }
        }

        $companySigners = [...$safes, $activeSafe, ...array_map('strtolower', $owners)];
        foreach ($verifierOwners as $verifierOwner) {
            if (in_array(strtolower($verifierOwner), $companySigners, true)) {
                throw ValidationException::withMessages([
                    'wallet_address' => 'This verifier Safe is controlled by a company signer. Use the verifier\'s own wallet or Safe.',
                ]);
            }
        }
    }
}
