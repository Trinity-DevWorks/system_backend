<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceChainReceiptData;
use App\Modules\InvoiceProof\DTOs\InvoiceOnChainRecord;
use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Exceptions\CompanySignerWalletException;
use App\Modules\InvoiceProof\Jobs\RegisterSalesInvoiceOnChainJob;
use App\Modules\InvoiceProof\Jobs\RevokeSalesInvoiceOnChainJob;
use App\Modules\InvoiceProof\Jobs\SetInvoiceReplacementOnChainJob;
use App\Modules\InvoiceProof\Jobs\SyncInvoiceRegistryPartiesJob;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\CompanySafeSignerGuard;
use App\Modules\InvoiceProof\Support\InvoicePartyWallets;
use App\Modules\InvoiceProof\Support\InvoiceProofBytes;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Queues and records InvoiceRegistry registration. Company (supplier) approval
 * is signed in the wallet, not broadcast by Laravel. Party addresses are optional
 * at register time and can be filled later via setParties.
 */
class InvoiceChainRegistrationService
{
    public function __construct(
        private readonly InvoiceRegistryGateway $invoiceRegistryGateway,
        private readonly CompanySafeSignerGuard $companySafeSignerGuard,
    ) {}

    public function isConfigured(): bool
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            return false;
        }

        if (! (bool) config('blockchain.enabled')) {
            return false;
        }

        foreach (
            [
                'blockchain.rpc_url',
                'blockchain.contract_address',
                'blockchain.registrar_address',
            ] as $key
        ) {
            if (trim((string) config($key)) === '') {
                return false;
            }
        }

        return true;
    }

    public function recordPending(InvoiceSnapshot $snapshot): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        InvoiceChainRegistration::query()->firstOrCreate(
            ['proof_id' => $snapshot->id],
            [
                'invoice_type' => InvoiceProofType::Sales,
                'invoice_id' => $snapshot->invoice_id,
                'status' => InvoiceChainRegistrationStatus::Pending,
            ],
        );
    }

    public function dispatchRegistration(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $tenantId = tenant('id');
        RegisterSalesInvoiceOnChainJob::dispatch(
            is_string($tenantId) ? $tenantId : null,
            $proofId,
        );
    }

    public function findForSalesInvoice(string $invoiceId): ?InvoiceChainRegistration
    {
        return InvoiceChainRegistration::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->where('invoice_id', $invoiceId)
            ->first();
    }

    public function revokeRequestedForSalesInvoice(string $invoiceId): bool
    {
        $registration = $this->findForSalesInvoice($invoiceId);

        return $registration !== null && $registration->revoke_requested_at !== null;
    }

    public function submitProof(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $snapshot = InvoiceSnapshot::query()->find($proofId);
        if ($snapshot === null) {
            return;
        }

        $registration = InvoiceChainRegistration::query()->firstOrCreate(
            ['proof_id' => $snapshot->id],
            [
                'invoice_type' => $snapshot->invoice_type,
                'invoice_id' => $snapshot->invoice_id,
                'status' => InvoiceChainRegistrationStatus::Pending,
            ],
        );

        if ($registration->status === InvoiceChainRegistrationStatus::Confirmed) {
            $this->syncParties($proofId);

            return;
        }

        $invoice = SalesInvoice::query()->with('customer')->find($snapshot->invoice_id);
        $supplier = InvoicePartyWallets::supplier(CompanyProfile::singleton());
        $buyer = InvoicePartyWallets::buyer($invoice?->customer);

        if (
            ! WalletAddress::isZero($supplier)
            && ! WalletAddress::isZero($buyer)
            && hash_equals($supplier, $buyer)
        ) {
            $registration->update([
                'status' => InvoiceChainRegistrationStatus::Failed,
                'last_error' => 'Company and customer wallets must be different.',
            ]);

            throw new RuntimeException('Company and customer wallets must be different.');
        }

        if (! WalletAddress::isZero($buyer)) {
            try {
                $this->companySafeSignerGuard->assertCustomerWalletAllowed($buyer);
            } catch (CompanySignerWalletException $exception) {
                $registration->update([
                    'status' => InvoiceChainRegistrationStatus::Failed,
                    'last_error' => $this->safeError($exception),
                ]);

                throw $exception;
            }
        }

        try {
            $receipt = $this->invoiceRegistryGateway->registerInvoice(
                (string) $snapshot->id,
                $snapshot->content_hash,
                $supplier,
                $buyer,
            );
            $this->markConfirmed($registration, $receipt);
        } catch (Throwable $exception) {
            $registration->update([
                'status' => InvoiceChainRegistrationStatus::Failed,
                'last_error' => $this->safeError($exception),
            ]);

            throw $exception;
        }

        if ($invoice?->status === SalesInvoiceStatus::Reversed) {
            $this->requestRevocation($invoice);
        }

        $this->onSuccessorRegistered($snapshot);
    }

    /**
     * Marks the invoice proof for revocation. The chain call runs once the registration is
     * confirmed: now if it already is, otherwise right after submitProof confirms it.
     */
    public function requestRevocation(SalesInvoice $invoice): void
    {
        $registration = $this->findForSalesInvoice((string) $invoice->id);
        if ($registration === null || $registration->revoked_at !== null) {
            return;
        }

        $registration->update([
            'revoke_requested_at' => $registration->revoke_requested_at ?? now(),
            'revoke_error' => null,
        ]);

        if ($registration->status === InvoiceChainRegistrationStatus::Confirmed) {
            $this->dispatchRevocation((string) $registration->proof_id);
        }
    }

    public function dispatchRevocation(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $tenantId = tenant('id');
        RevokeSalesInvoiceOnChainJob::dispatch(
            is_string($tenantId) ? $tenantId : null,
            $proofId,
        );
    }

    /**
     * Consistency-check retry for a reversed invoice whose proof is still live on chain.
     */
    public function requeueRevocation(string $proofId): void
    {
        InvoiceChainRegistration::query()
            ->where('proof_id', $proofId)
            ->whereNull('revoke_requested_at')
            ->update(['revoke_requested_at' => now()]);

        $this->dispatchRevocation($proofId);
    }

    /**
     * Remember that `$originInvoiceId` is superseded by `$replacementProofId`, then
     * revoke-with-replacement or `setReplacement` depending on whether the old proof
     * is already revoked.
     */
    public function attachSuccessor(string $originInvoiceId, string $replacementProofId): void
    {
        $registration = $this->findForSalesInvoice($originInvoiceId);
        if ($registration === null) {
            return;
        }

        $registration->update(['replaced_by' => $replacementProofId]);

        if ($registration->revoked_at !== null) {
            $this->dispatchReplacement((string) $registration->proof_id);

            return;
        }

        if (
            $registration->revoke_requested_at !== null
            && $registration->status === InvoiceChainRegistrationStatus::Confirmed
        ) {
            $this->dispatchRevocation((string) $registration->proof_id);
        }
    }

    public function dispatchReplacement(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $tenantId = tenant('id');
        SetInvoiceReplacementOnChainJob::dispatch(
            is_string($tenantId) ? $tenantId : null,
            $proofId,
        );
    }

    public function submitRevocation(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $registration = InvoiceChainRegistration::query()->where('proof_id', $proofId)->first();
        if (
            $registration === null
            || $registration->status !== InvoiceChainRegistrationStatus::Confirmed
            || $registration->revoke_requested_at === null
        ) {
            return;
        }

        if ($registration->revoked_at !== null) {
            $this->submitReplacement($proofId);

            return;
        }

        try {
            $receipt = $this->invoiceRegistryGateway->revokeInvoice(
                $proofId,
                is_string($registration->replaced_by) ? $registration->replaced_by : null,
            );
        } catch (Throwable $exception) {
            $registration->update(['revoke_error' => $this->safeError($exception)]);

            throw $exception;
        }

        $onChainReplacedBy = $this->invoiceRegistryGateway->invoiceOf($proofId)?->replacedBy;

        $registration->update([
            'revoked_at' => now(),
            'revoke_tx_hash' => $receipt->txHash,
            'revoke_error' => null,
            'replaced_by' => $onChainReplacedBy ?? $registration->replaced_by,
            'chain_status' => InvoiceProofVerificationStatus::Revoked,
            'status_checked_at' => now(),
        ]);
    }

    public function submitReplacement(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $registration = InvoiceChainRegistration::query()->where('proof_id', $proofId)->first();
        if (
            $registration === null
            || $registration->status !== InvoiceChainRegistrationStatus::Confirmed
            || $registration->revoked_at === null
            || ! is_string($registration->replaced_by)
            || $registration->replaced_by === ''
        ) {
            return;
        }

        try {
            $this->invoiceRegistryGateway->setReplacement($proofId, $registration->replaced_by);
        } catch (Throwable $exception) {
            $registration->update(['revoke_error' => $this->safeError($exception)]);

            throw $exception;
        }

        $registration->update(['revoke_error' => null]);
    }

    private function onSuccessorRegistered(InvoiceSnapshot $snapshot): void
    {
        $invoice = SalesInvoice::query()->find($snapshot->invoice_id);
        $originId = $invoice?->replaces_invoice_id;
        if (! is_string($originId) || $originId === '') {
            return;
        }

        $this->attachSuccessor($originId, (string) $snapshot->id);
    }

    public function syncDisputeFromChain(string $proofId, InvoiceOnChainRecord $onChain): void
    {
        if ($onChain->disputedAt === null) {
            return;
        }

        $values = [
            'disputed_at' => $onChain->disputedAt > 0
                ? Carbon::createFromTimestampUTC($onChain->disputedAt)
                : now(),
            'dispute_reason_hash' => $onChain->disputeReasonHash,
            'chain_status' => InvoiceProofVerificationStatus::Disputed,
            'status_checked_at' => now(),
        ];

        InvoiceChainRegistration::query()
            ->where('proof_id', $proofId)
            ->whereNull('disputed_at')
            ->update($values);

        InvoiceChainRegistration::query()
            ->where('proof_id', $proofId)
            ->whereNotNull('disputed_at')
            ->whereNull('dispute_reason_hash')
            ->update(['dispute_reason_hash' => $onChain->disputeReasonHash]);
    }

    /**
     * Stores the buyer reason once the chain already records that dispute.
     * keccak256(reason) must equal the on-chain disputeReasonHash.
     */
    public function storeDisputeReason(string $proofId, string $reason, ?string $txHash = null): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            abort(422, 'A dispute reason is required.', [
                'X-Error-Code' => 'VALIDATION_ERROR',
            ]);
        }

        $registration = InvoiceChainRegistration::query()->where('proof_id', $proofId)->first();
        if ($registration === null) {
            abort(422, 'This invoice is not registered on the blockchain.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
        } catch (Throwable) {
            abort(503, 'Could not read the invoice from the blockchain.', [
                'X-Error-Code' => 'INVOICE_PROOF_CHAIN_FAILED',
            ]);
        }

        if ($onChain === null || $onChain->disputedAt === null || $onChain->disputeReasonHash === null) {
            abort(422, 'This invoice is not disputed on the blockchain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_DISPUTED',
            ]);
        }

        $hash = InvoiceProofBytes::keccakUtf8($reason);
        if (! hash_equals(strtolower($onChain->disputeReasonHash), strtolower($hash))) {
            abort(422, 'The dispute reason does not match the hash on the blockchain.', [
                'X-Error-Code' => 'INVOICE_PROOF_DISPUTE_REASON_MISMATCH',
            ]);
        }

        $this->syncDisputeFromChain($proofId, $onChain);
        $registration->refresh();
        $registration->update([
            'dispute_reason' => $reason,
            'dispute_reason_hash' => $hash,
            'dispute_tx_hash' => $txHash ?? $registration->dispute_tx_hash,
        ]);
    }

    public function salesInvoiceIsDisputed(SalesInvoice $invoice): bool
    {
        $registration = $this->findForSalesInvoice((string) $invoice->id);
        if ($registration === null) {
            return false;
        }

        if ($registration->disputed_at !== null || $registration->chain_status === InvoiceProofVerificationStatus::Disputed) {
            return true;
        }

        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf((string) $registration->proof_id);
        } catch (Throwable) {
            return false;
        }

        return $onChain?->disputedAt !== null;
    }

    /**
     * Fill empty on-chain party slots from current ERP wallets without changing contentHash.
     */
    public function syncParties(string $proofId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $snapshot = InvoiceSnapshot::query()->find($proofId);
        if ($snapshot === null) {
            return;
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
        } catch (Throwable) {
            return;
        }

        if ($onChain === null || $onChain->revokedAt !== null || $onChain->disputedAt !== null) {
            return;
        }

        $invoice = SalesInvoice::query()->with('customer')->find($snapshot->invoice_id);
        $desiredSupplier = InvoicePartyWallets::optionalSupplier(CompanyProfile::singleton());
        $desiredBuyer = InvoicePartyWallets::optionalBuyer($invoice?->customer);

        $currentSupplier = WalletAddress::normalize($onChain->supplierAddress);
        $currentBuyer = WalletAddress::normalize($onChain->buyerAddress);

        $supplierArg = ($currentSupplier === null && $desiredSupplier !== null)
            ? $desiredSupplier
            : WalletAddress::ZERO;
        $buyerArg = ($currentBuyer === null && $desiredBuyer !== null)
            ? $desiredBuyer
            : WalletAddress::ZERO;

        if (WalletAddress::isZero($supplierArg) && WalletAddress::isZero($buyerArg)) {
            return;
        }

        $finalSupplier = ! WalletAddress::isZero($supplierArg) ? $supplierArg : $currentSupplier;
        $finalBuyer = ! WalletAddress::isZero($buyerArg) ? $buyerArg : $currentBuyer;
        if (
            $finalSupplier !== null
            && $finalBuyer !== null
            && hash_equals($finalSupplier, $finalBuyer)
        ) {
            return;
        }

        if ($desiredBuyer !== null) {
            $this->companySafeSignerGuard->assertCustomerWalletAllowed($desiredBuyer);
        }

        $this->invoiceRegistryGateway->setParties($proofId, $supplierArg, $buyerArg);
    }

    public function dispatchSupplierPartySync(): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        if (InvoicePartyWallets::optionalSupplier(CompanyProfile::singleton()) === null) {
            return;
        }

        $tenantId = tenant('id');
        $tenantKey = is_string($tenantId) ? $tenantId : null;

        InvoiceChainRegistration::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->where('status', InvoiceChainRegistrationStatus::Confirmed)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use ($tenantKey): void {
                foreach ($rows as $registration) {
                    SyncInvoiceRegistryPartiesJob::dispatch($tenantKey, (string) $registration->proof_id);
                }
            });
    }

    public function dispatchBuyerPartySync(string $customerId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $tenantId = tenant('id');
        $tenantKey = is_string($tenantId) ? $tenantId : null;

        $invoiceIds = SalesInvoice::query()
            ->where('customer_id', $customerId)
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return;
        }

        InvoiceChainRegistration::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->where('status', InvoiceChainRegistrationStatus::Confirmed)
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use ($tenantKey): void {
                foreach ($rows as $registration) {
                    SyncInvoiceRegistryPartiesJob::dispatch($tenantKey, (string) $registration->proof_id);
                }
            });
    }

    public function approveAsCompany(SalesInvoice $invoice): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }

        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            abort(422, 'Only posted sales invoices can be approved.', [
                'X-Error-Code' => 'SALES_INVOICE_NOT_POSTED',
            ]);
        }

        if (! $this->isConfigured()) {
            abort(422, 'This invoice is not registered on chain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }

        $proof = app(InvoiceProofVerificationService::class)->verifySalesInvoice($invoice);
        if ($proof->status === InvoiceProofVerificationStatus::Tampered) {
            abort(422, 'Invoice proof does not match. Approval is blocked.', [
                'X-Error-Code' => 'INVOICE_PROOF_TAMPERED',
            ]);
        }

        $snapshot = InvoiceSnapshot::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->where('invoice_id', $invoice->id)
            ->first();

        if ($snapshot === null) {
            abort(422, 'This invoice is not registered on chain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf((string) $snapshot->id);
        } catch (Throwable $exception) {
            abort(503, $this->safeError($exception), [
                'X-Error-Code' => 'INVOICE_PROOF_CHAIN_FAILED',
            ]);
        }

        if ($onChain === null) {
            abort(422, 'This invoice is not registered on chain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }

        if (WalletAddress::isZero($onChain->supplierAddress)) {
            abort(422, 'Company wallet is not set on this proof yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_PARTY_NOT_SET',
            ]);
        }

        if ($onChain->status !== InvoiceOnChainStatus::Registered) {
            abort(422, 'The company has already approved this invoice.', [
                'X-Error-Code' => 'INVOICE_PROOF_COMPANY_ALREADY_APPROVED',
            ]);
        }

        abort(422, 'Company approval must be signed in the company wallet.', [
            'X-Error-Code' => 'COMPANY_APPROVAL_REQUIRES_WALLET',
        ]);
    }

    private function markConfirmed(InvoiceChainRegistration $registration, InvoiceChainReceiptData $receipt): void
    {
        $registration->update([
            'status' => InvoiceChainRegistrationStatus::Confirmed,
            'tx_hash' => $receipt->txHash,
            'block_number' => $receipt->blockNumber,
            'contract_address' => $receipt->contractAddress,
            'last_error' => null,
            'chain_status' => InvoiceProofVerificationStatus::WaitingCompany,
            'financed_at' => null,
            'status_checked_at' => now(),
        ]);
    }

    private function safeError(Throwable $exception): string
    {
        return Str::limit($exception->getMessage(), 500);
    }
}
