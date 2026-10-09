<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Models\Tenant;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
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
                'invoice_type' => $snapshot->invoice_type instanceof InvoiceProofType
                    ? $snapshot->invoice_type
                    : InvoiceProofType::from((string) $snapshot->invoice_type),
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
        return $this->findForInvoice(InvoiceProofType::Sales, $invoiceId);
    }

    public function findForPurchaseInvoice(string $invoiceId): ?InvoiceChainRegistration
    {
        return $this->findForInvoice(InvoiceProofType::Purchase, $invoiceId);
    }

    public function findForInvoice(InvoiceProofType $type, string $invoiceId): ?InvoiceChainRegistration
    {
        return InvoiceChainRegistration::query()
            ->where('invoice_type', $type)
            ->where('invoice_id', $invoiceId)
            ->first();
    }

    public function revokeRequestedForSalesInvoice(string $invoiceId): bool
    {
        return $this->revokeRequestedForInvoice(InvoiceProofType::Sales, $invoiceId);
    }

    public function revokeRequestedForPurchaseInvoice(string $invoiceId): bool
    {
        return $this->revokeRequestedForInvoice(InvoiceProofType::Purchase, $invoiceId);
    }

    public function revokeRequestedForInvoice(InvoiceProofType $type, string $invoiceId): bool
    {
        $registration = $this->findForInvoice($type, $invoiceId);

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

        $parties = $this->partyWalletsForSnapshot($snapshot);
        $supplier = $parties['supplier'];
        $buyer = $parties['buyer'];

        if (
            ! WalletAddress::isZero($supplier)
            && ! WalletAddress::isZero($buyer)
            && hash_equals($supplier, $buyer)
        ) {
            $registration->update([
                'status' => InvoiceChainRegistrationStatus::Failed,
                'last_error' => 'Company and counterparty wallets must be different.',
            ]);

            throw new RuntimeException('Company and counterparty wallets must be different.');
        }

        $counterparty = $this->isPurchaseSnapshot($snapshot) ? $supplier : $buyer;
        if (! WalletAddress::isZero($counterparty)) {
            try {
                $this->companySafeSignerGuard->assertCustomerWalletAllowed($counterparty);
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

        if ($this->documentIsReversed($snapshot)) {
            $this->requestRevocationForSnapshot($snapshot);
        }

        $this->onSuccessorRegistered($snapshot);

        if (! $this->isPurchaseSnapshot($snapshot) && ! $this->documentIsReversed($snapshot)) {
            try {
                app(TenantSalesInvoiceDelivery::class)->deliver($snapshot);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /**
     * Marks the invoice proof for revocation. The chain call runs once the registration is
     * confirmed: now if it already is, otherwise right after submitProof confirms it.
     */
    public function requestRevocation(SalesInvoice $invoice): void
    {
        $this->requestRevocationForInvoice(InvoiceProofType::Sales, (string) $invoice->id);
    }

    public function requestPurchaseRevocation(PurchaseInvoice $invoice): void
    {
        $this->requestRevocationForInvoice(InvoiceProofType::Purchase, (string) $invoice->id);
    }

    public function requestRevocationForInvoice(InvoiceProofType $type, string $invoiceId): void
    {
        $registration = $this->findForInvoice($type, $invoiceId);
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
        $this->attachSuccessorFor(InvoiceProofType::Sales, $originInvoiceId, $replacementProofId);
    }

    public function attachPurchaseSuccessor(string $originInvoiceId, string $replacementProofId): void
    {
        $this->attachSuccessorFor(InvoiceProofType::Purchase, $originInvoiceId, $replacementProofId);
    }

    public function attachSuccessorFor(InvoiceProofType $type, string $originInvoiceId, string $replacementProofId): void
    {
        $registration = $this->findForInvoice($type, $originInvoiceId);
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
        $type = $snapshot->invoice_type instanceof InvoiceProofType
            ? $snapshot->invoice_type
            : InvoiceProofType::from((string) $snapshot->invoice_type);

        if ($type === InvoiceProofType::Purchase) {
            $invoice = PurchaseInvoice::query()->find($snapshot->invoice_id);
            $originId = $invoice?->replaces_invoice_id ?? null;
        } else {
            $invoice = SalesInvoice::query()->find($snapshot->invoice_id);
            $originId = $invoice?->replaces_invoice_id ?? null;
        }

        if (! is_string($originId) || $originId === '') {
            return;
        }

        $this->attachSuccessorFor($type, $originId, (string) $snapshot->id);
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

    public function salesInvoiceIsRevoked(SalesInvoice $invoice): bool
    {
        $registration = $this->findForSalesInvoice((string) $invoice->id);
        if ($registration === null) {
            return false;
        }

        if ($this->registrationIsRevoked($registration)) {
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

        return $onChain?->revokedAt !== null;
    }

    /**
     * A buyer-issued purchase invoice is disputed on its own registration.
     * A purchase invoice linked to another tenant's sales invoice is disputed on that proof.
     */
    public function purchaseInvoiceIsDisputed(PurchaseInvoice $invoice): bool
    {
        if ($this->registrationIsDisputed($this->findForPurchaseInvoice((string) $invoice->id))) {
            return true;
        }

        if (is_string($invoice->linked_dispute_reason) && trim($invoice->linked_dispute_reason) !== '') {
            return true;
        }

        $linkedProofId = is_string($invoice->linked_proof_id) && $invoice->linked_proof_id !== ''
            ? $invoice->linked_proof_id
            : null;
        if ($linkedProofId !== null && $this->registrationIsDisputed(
            InvoiceChainRegistration::query()->where('proof_id', $linkedProofId)->first(),
        )) {
            return true;
        }

        $proofId = $linkedProofId ?? $this->findForPurchaseInvoice((string) $invoice->id)?->proof_id;
        if (! is_string($proofId) || $proofId === '' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
        } catch (Throwable) {
            return false;
        }

        return $onChain?->disputedAt !== null;
    }

    /**
     * A still-posted invoice whose chain seal was revoked is closed for payment.
     * A linked purchase invoice follows the sales-invoice proof.
     */
    public function purchaseInvoiceIsRevoked(PurchaseInvoice $invoice): bool
    {
        if ($this->registrationIsRevoked($this->findForPurchaseInvoice((string) $invoice->id))) {
            return true;
        }

        $linkedProofId = is_string($invoice->linked_proof_id) && $invoice->linked_proof_id !== ''
            ? $invoice->linked_proof_id
            : null;
        if ($linkedProofId !== null && $this->registrationIsRevoked(
            InvoiceChainRegistration::query()->where('proof_id', $linkedProofId)->first(),
        )) {
            return true;
        }

        $proofId = $linkedProofId ?? $this->findForPurchaseInvoice((string) $invoice->id)?->proof_id;
        if (! is_string($proofId) || $proofId === '' || ! $this->isConfigured()) {
            return false;
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
        } catch (Throwable) {
            return false;
        }

        return $onChain?->revokedAt !== null;
    }

    private function registrationIsRevoked(?InvoiceChainRegistration $registration): bool
    {
        return $registration !== null && (
            $registration->revoked_at !== null
            || $registration->chain_status === InvoiceProofVerificationStatus::Revoked
        );
    }

    private function registrationIsDisputed(?InvoiceChainRegistration $registration): bool
    {
        return $registration !== null && (
            $registration->disputed_at !== null
            || $registration->chain_status === InvoiceProofVerificationStatus::Disputed
        );
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

        $parties = $this->partyWalletsForSnapshot($snapshot);
        $desiredSupplier = WalletAddress::isZero($parties['supplier']) ? null : $parties['supplier'];
        $desiredBuyer = WalletAddress::isZero($parties['buyer']) ? null : $parties['buyer'];

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

        if ($this->isPurchaseSnapshot($snapshot)) {
            if ($desiredSupplier !== null) {
                $this->companySafeSignerGuard->assertCustomerWalletAllowed($desiredSupplier);
            }
        } elseif ($desiredBuyer !== null) {
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

    /**
     * Purchase invoices: our company is the on-chain buyer. Laravel never broadcasts.
     */
    /**
     * A purchase invoice may point at a sales-invoice proof that is already on chain.
     * The on-chain buyer must be this company's wallet. Revoked and disputed proofs cannot be linked.
     */
    public function assertExternalProofLink(string $proofId): InvoiceOnChainRecord
    {
        $onChain = $this->readExternalProof($proofId);
        $company = WalletAddress::normalize(CompanyProfile::singleton()->wallet_address);
        if ($company === null) {
            abort(422, 'Set the company wallet before linking a supplier proof.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF_NO_COMPANY_WALLET',
            ]);
        }
        if (WalletAddress::normalize($onChain->buyerAddress) !== $company) {
            abort(422, 'The buyer on this proof is not your company wallet.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF_BUYER_MISMATCH',
            ]);
        }
        if ($onChain->status === InvoiceOnChainStatus::Revoked || $onChain->status === InvoiceOnChainStatus::Disputed) {
            abort(422, 'This supplier proof is revoked or disputed.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF_CLOSED',
            ]);
        }

        return $onChain;
    }

    /**
     * A linked purchase invoice is posted only after the supplier has approved the seal.
     */
    public function assertLinkedPurchaseCanPost(string $proofId): void
    {
        $onChain = $this->assertExternalProofLink($proofId);
        if ($onChain->status === InvoiceOnChainStatus::SupplierApproved
            || $onChain->status === InvoiceOnChainStatus::FullyApproved) {
            return;
        }

        abort(422, 'The supplier has not approved this invoice yet.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_SUPPLIER_NOT_APPROVED',
        ]);
    }

    /**
     * While the supplier's seal is waiting for this buyer, dispute comes before reverse.
     * Reverse does not write to that seal.
     */
    public function assertLinkedPurchaseCanReverse(string $proofId): void
    {
        $onChain = $this->readExternalProof($proofId);
        if ($onChain->status !== InvoiceOnChainStatus::SupplierApproved) {
            return;
        }

        abort(422, 'Dispute this invoice before reversing it.', [
            'X-Error-Code' => 'PURCHASE_INVOICE_DISPUTE_BEFORE_REVERSE',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function previewExternalProof(string $proofId): array
    {
        $onChain = $this->readExternalProof($proofId);
        $company = WalletAddress::normalize(CompanyProfile::singleton()->wallet_address);

        return [
            'proof_id' => $proofId,
            'supplier_wallet' => WalletAddress::nonZeroOrNull($onChain->supplierAddress),
            'buyer_wallet' => WalletAddress::nonZeroOrNull($onChain->buyerAddress),
            'status' => InvoiceProofVerificationService::statusFromChain($onChain)->value,
            'buyer_matches_company' => $company !== null && WalletAddress::normalize($onChain->buyerAddress) === $company,
            'company_wallet_set' => $company !== null,
            'registered_at' => $onChain->registeredAt !== null && $onChain->registeredAt > 0
                ? Carbon::createFromTimestampUTC($onChain->registeredAt)->toIso8601String()
                : null,
        ];
    }

    public function storeExternalDisputeReason(PurchaseInvoice $invoice, string $reason, ?string $txHash = null): void
    {
        $proofId = $invoice->linked_proof_id;
        if (! is_string($proofId) || $proofId === '') {
            abort(422, 'This invoice is not linked to a supplier proof.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF_NOT_FOUND',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            abort(422, 'A dispute reason is required.', [
                'X-Error-Code' => 'VALIDATION_ERROR',
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

        $invoice->update(['linked_dispute_reason' => $reason]);
        $this->syncDisputeFromChain($proofId, $onChain);
        $this->publishDisputeReason($proofId, $reason, $hash);
        unset($txHash);
    }

    /**
     * The buyer text is not on chain. Copy it onto every tenant that registered this proof,
     * so the supplier sales invoice can show the same reason.
     */
    private function publishDisputeReason(string $proofId, string $reason, string $hash): void
    {
        $write = function () use ($proofId, $reason, $hash): void {
            InvoiceChainRegistration::query()
                ->where('proof_id', $proofId)
                ->update([
                    'dispute_reason' => $reason,
                    'dispute_reason_hash' => $hash,
                ]);
        };

        $write();

        $currentId = tenant('id');
        Tenant::query()
            ->when(is_string($currentId) && $currentId !== '', fn ($query) => $query->where('id', '!=', $currentId))
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($write): void {
                $tenant->run($write);
            });
    }

    private function readExternalProof(string $proofId): InvoiceOnChainRecord
    {
        if (! CompanySetting::current()->invoiceProofsEnabled() || ! $this->isConfigured()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
        } catch (Throwable) {
            abort(503, 'Could not read the invoice from the blockchain.', [
                'X-Error-Code' => 'INVOICE_PROOF_CHAIN_FAILED',
            ]);
        }

        if ($onChain === null) {
            abort(422, 'This proof is not on the blockchain.', [
                'X-Error-Code' => 'PURCHASE_INVOICE_LINKED_PROOF_NOT_FOUND',
            ]);
        }

        return $onChain;
    }

    public function assertPurchaseBuyerWalletAction(PurchaseInvoice $invoice): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(403, 'Invoice proofs are disabled for this company.', [
                'X-Error-Code' => 'INVOICE_PROOFS_DISABLED',
            ]);
        }

        PurchaseInvoiceRules::assertPostedForProof($invoice);

        if (! $this->isConfigured()) {
            abort(422, 'This invoice is not registered on chain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }

        $proof = app(InvoiceProofVerificationService::class)->verifyPurchaseInvoice($invoice);
        if ($proof->status === InvoiceProofVerificationStatus::Tampered) {
            abort(422, 'Invoice proof does not match. Approval is blocked.', [
                'X-Error-Code' => 'INVOICE_PROOF_TAMPERED',
            ]);
        }

        $proofId = is_string($invoice->linked_proof_id) && $invoice->linked_proof_id !== ''
            ? $invoice->linked_proof_id
            : null;
        if ($proofId === null) {
            $snapshot = InvoiceSnapshot::query()
                ->where('invoice_type', InvoiceProofType::Purchase)
                ->where('invoice_id', $invoice->id)
                ->first();
            $proofId = $snapshot !== null ? (string) $snapshot->id : null;
        }

        if ($proofId === null) {
            abort(422, 'This invoice is not registered on chain yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_NOT_ON_CHAIN',
            ]);
        }

        try {
            $onChain = $this->invoiceRegistryGateway->invoiceOf($proofId);
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

        if (WalletAddress::isZero($onChain->buyerAddress)) {
            abort(422, 'Company wallet is not set on this proof yet.', [
                'X-Error-Code' => 'INVOICE_PROOF_PARTY_NOT_SET',
            ]);
        }

        abort(422, 'Buyer approval must be signed in the company wallet.', [
            'X-Error-Code' => 'BUYER_APPROVAL_REQUIRES_WALLET',
        ]);
    }

    public function dispatchVendorPartySync(string $supplierId): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        $tenantId = tenant('id');
        $tenantKey = is_string($tenantId) ? $tenantId : null;
        $invoiceIds = PurchaseInvoice::query()
            ->where('supplier_id', $supplierId)
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return;
        }

        InvoiceChainRegistration::query()
            ->where('invoice_type', InvoiceProofType::Purchase)
            ->where('status', InvoiceChainRegistrationStatus::Confirmed)
            ->whereIn('invoice_id', $invoiceIds)
            ->orderBy('id')
            ->chunkById(50, function ($rows) use ($tenantKey): void {
                foreach ($rows as $registration) {
                    SyncInvoiceRegistryPartiesJob::dispatch($tenantKey, (string) $registration->proof_id);
                }
            });
    }

    /**
     * @return array{supplier: string, buyer: string}
     */
    private function partyWalletsForSnapshot(InvoiceSnapshot $snapshot): array
    {
        $company = CompanyProfile::singleton();
        if ($this->isPurchaseSnapshot($snapshot)) {
            $invoice = PurchaseInvoice::query()->with('supplier')->find($snapshot->invoice_id);

            return InvoicePartyWallets::forPurchase($company, $invoice?->supplier);
        }

        $invoice = SalesInvoice::query()->with('customer')->find($snapshot->invoice_id);

        return InvoicePartyWallets::forSales($company, $invoice?->customer);
    }

    private function isPurchaseSnapshot(InvoiceSnapshot $snapshot): bool
    {
        $type = $snapshot->invoice_type instanceof InvoiceProofType
            ? $snapshot->invoice_type
            : InvoiceProofType::from((string) $snapshot->invoice_type);

        return $type === InvoiceProofType::Purchase;
    }

    private function documentIsReversed(InvoiceSnapshot $snapshot): bool
    {
        if ($this->isPurchaseSnapshot($snapshot)) {
            $invoice = PurchaseInvoice::query()->find($snapshot->invoice_id);

            return $invoice?->status === PurchaseInvoiceStatus::Reversed;
        }

        $invoice = SalesInvoice::query()->find($snapshot->invoice_id);

        return $invoice?->status === SalesInvoiceStatus::Reversed;
    }

    private function requestRevocationForSnapshot(InvoiceSnapshot $snapshot): void
    {
        $type = $snapshot->invoice_type instanceof InvoiceProofType
            ? $snapshot->invoice_type
            : InvoiceProofType::from((string) $snapshot->invoice_type);

        $this->requestRevocationForInvoice($type, (string) $snapshot->invoice_id);
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
