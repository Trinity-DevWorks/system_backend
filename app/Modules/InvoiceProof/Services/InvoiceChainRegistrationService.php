<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Contracts\InvoiceRegistryGateway;
use App\Modules\InvoiceProof\DTOs\InvoiceChainReceiptData;
use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceOnChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Exceptions\CompanySignerWalletException;
use App\Modules\InvoiceProof\Jobs\RegisterSalesInvoiceOnChainJob;
use App\Modules\InvoiceProof\Jobs\SyncInvoiceRegistryPartiesJob;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\CompanySafeSignerGuard;
use App\Modules\InvoiceProof\Support\InvoicePartyWallets;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
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

        if ($onChain === null) {
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
        ]);
    }

    private function safeError(Throwable $exception): string
    {
        return Str::limit($exception->getMessage(), 500);
    }
}
