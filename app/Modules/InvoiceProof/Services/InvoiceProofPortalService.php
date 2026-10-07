<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\InvoiceProof\Contracts\CompanySafeOwnerLookup;
use App\Modules\InvoiceProof\DTOs\BuyerInvoiceHistoryItemData;
use App\Modules\InvoiceProof\DTOs\BuyerPortalLinkData;
use App\Modules\InvoiceProof\DTOs\InvoiceProofPortalChallengeData;
use App\Modules\InvoiceProof\DTOs\InvoiceProofPortalData;
use App\Modules\InvoiceProof\Support\EthereumPersonalSign;
use App\Modules\InvoiceProof\Support\InvoiceApprovalStatement;
use App\Modules\InvoiceProof\Support\ProofPortalHistoryChallenge;
use App\Modules\InvoiceProof\Support\ProofPortalLink;
use App\Modules\InvoiceProof\Support\ProofPortalSession;
use App\Modules\InvoiceProof\Support\ProofPortalUnlockChallenge;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use JsonException;
use Throwable;

/**
 * Public buyer-portal lookup. HMAC exp+sig is the capability; UUID alone is not.
 * GET returns a locked personal_sign challenge. Commercial fields come from the
 * sealed snapshot after POST unlock with the customer wallet (or an owner of the
 * customer's Safe).
 */
class InvoiceProofPortalService
{
    public function __construct(
        private readonly InvoiceSnapshotService $invoiceSnapshotService,
        private readonly InvoiceProofVerificationService $invoiceProofVerificationService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
        private readonly CompanySafeOwnerLookup $companySafeOwnerLookup,
    ) {}

    public function assertValidLink(string $invoiceId, mixed $exp, mixed $sig): void
    {
        $tenantId = (string) tenant('id');
        ProofPortalLink::assertValid($tenantId, $invoiceId, $exp, $sig);
    }

    public function issueLink(SalesInvoice $invoice): BuyerPortalLinkData
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(
                403,
                'Invoice proofs are disabled for this company.',
                ['X-Error-Code' => 'INVOICE_PROOFS_DISABLED']
            );
        }

        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            abort(422, 'Only posted sales invoices can be approved.', [
                'X-Error-Code' => 'SALES_INVOICE_NOT_POSTED',
            ]);
        }

        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $tenantId = (string) tenant('id');

        return BuyerPortalLinkData::fromIssued(
            ProofPortalLink::issue($tenantId, (string) $invoice->id)
        );
    }

    public function issueVendorLink(PurchaseInvoice $invoice): BuyerPortalLinkData
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(
                403,
                'Invoice proofs are disabled for this company.',
                ['X-Error-Code' => 'INVOICE_PROOFS_DISABLED']
            );
        }

        PurchaseInvoiceRules::assertPostedForProof($invoice);

        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $tenantId = (string) tenant('id');

        return BuyerPortalLinkData::fromIssued(
            ProofPortalLink::issue($tenantId, (string) $invoice->id, '/proofs/purchases/')
        );
    }

    public function challenge(SalesInvoice $invoice): InvoiceProofPortalChallengeData
    {
        $this->assertPortalInvoice($invoice);
        $invoice->loadMissing('customer');
        $issued = ProofPortalUnlockChallenge::issue(
            (string) tenant('id'),
            (string) $invoice->id,
            (string) CompanyProfile::singleton()->company_name,
            (string) $invoice->invoice_number,
        );

        $buyerWallet = WalletAddress::normalize($invoice->customer?->wallet_address);

        return new InvoiceProofPortalChallengeData(
            locked: true,
            chainId: (int) config('blockchain.chain_id'),
            buyerWallet: $buyerWallet,
            nonce: $issued['nonce'],
            message: $issued['message'],
            buyerWalletType: $buyerWallet === null ? null : $invoice->customer?->wallet_type?->value,
        );
    }

    public function unlock(SalesInvoice $invoice, string $address, string $signature): InvoiceProofPortalData
    {
        $this->assertPortalInvoice($invoice);
        $invoice->loadMissing('customer');

        $expected = WalletAddress::normalize($invoice->customer?->wallet_address);
        if ($expected === null) {
            abort(422, 'No buyer wallet is configured for this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_REQUIRED',
            ]);
        }

        $submitted = WalletAddress::normalize($address);
        $tenantId = (string) tenant('id');
        $invoiceId = (string) $invoice->id;
        $nonce = ProofPortalUnlockChallenge::current($tenantId, $invoiceId);
        if ($nonce === null) {
            abort(422, 'This unlock challenge has expired. Refresh and try again.', [
                'X-Error-Code' => 'PROOF_UNLOCK_INVALID',
            ]);
        }

        $message = ProofPortalUnlockChallenge::message(
            (string) CompanyProfile::singleton()->company_name,
            (string) $invoice->invoice_number,
            $nonce,
        );
        $recovered = EthereumPersonalSign::recoverAddress($message, $signature);
        if (
            $submitted === null
            || $recovered === null
            || ! hash_equals($submitted, $recovered)
            || ! $this->canActForBuyer($expected, $recovered)
        ) {
            abort(422, 'The connected wallet does not match this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_MISMATCH',
            ]);
        }

        ProofPortalUnlockChallenge::consume($tenantId, $invoiceId, $nonce);

        return $this->show($invoice);
    }

    public function resume(SalesInvoice $invoice, string $token, string $address): InvoiceProofPortalData
    {
        $this->assertPortalInvoice($invoice);
        $invoice->loadMissing('customer');
        $this->assertSession($token, $address, ProofPortalSession::ROLE_BUYER, $invoice->customer?->wallet_address);

        return $this->show($invoice);
    }

    public function recordDispute(SalesInvoice $invoice, string $reason, ?string $txHash = null): InvoiceProofPortalData
    {
        $this->assertPortalInvoice($invoice);
        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $this->invoiceChainRegistrationService->storeDisputeReason((string) $snapshot->id, $reason, $txHash);

        return $this->show($invoice);
    }

    /**
     * The buyer wallet itself, or an owner of the buyer Safe (a Safe cannot personal_sign).
     */
    private function canActForBuyer(string $buyerWallet, string $signer): bool
    {
        if (hash_equals($buyerWallet, $signer)) {
            return true;
        }

        try {
            $owners = $this->companySafeOwnerLookup->ownersOf($buyerWallet);
        } catch (Throwable) {
            abort(503, 'Could not read the buyer Safe owners from the chain.', [
                'X-Error-Code' => 'INVOICE_PROOF_CHAIN_FAILED',
            ]);
        }

        return in_array($signer, $owners, true);
    }

    public function show(SalesInvoice $invoice): InvoiceProofPortalData
    {
        $this->assertPortalInvoice($invoice);

        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $company = CompanyProfile::singleton();

        $invoice->loadMissing(['customer', 'replacesInvoice', 'replacedByInvoice']);

        return InvoiceProofPortalData::fromSnapshot(
            (string) $invoice->id,
            $snapshot,
            $this->invoiceProofVerificationService->verifySalesInvoice($invoice, $company),
            $this->historyItemsForCustomer((string) $invoice->customer_id, (string) $invoice->id),
            $this->signedPortalInvoice($invoice->replacedByInvoice),
            $this->signedPortalInvoice($invoice->replacesInvoice),
        );
    }

    public function challengePurchase(PurchaseInvoice $invoice): InvoiceProofPortalChallengeData
    {
        $this->assertPurchasePortalInvoice($invoice);
        $invoice->loadMissing('supplier');
        $issued = ProofPortalUnlockChallenge::issue(
            (string) tenant('id'),
            (string) $invoice->id,
            (string) CompanyProfile::singleton()->company_name,
            (string) $invoice->invoice_number,
        );

        $vendorWallet = WalletAddress::normalize($invoice->supplier?->wallet_address);

        return new InvoiceProofPortalChallengeData(
            locked: true,
            chainId: (int) config('blockchain.chain_id'),
            buyerWallet: $vendorWallet,
            nonce: $issued['nonce'],
            message: $issued['message'],
            buyerWalletType: $vendorWallet === null ? null : $invoice->supplier?->wallet_type?->value,
        );
    }

    public function unlockPurchase(PurchaseInvoice $invoice, string $address, string $signature): InvoiceProofPortalData
    {
        $this->assertPurchasePortalInvoice($invoice);
        $invoice->loadMissing('supplier');

        $expected = WalletAddress::normalize($invoice->supplier?->wallet_address);
        if ($expected === null) {
            abort(422, 'No vendor wallet is configured for this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_REQUIRED',
            ]);
        }

        $submitted = WalletAddress::normalize($address);
        $tenantId = (string) tenant('id');
        $invoiceId = (string) $invoice->id;
        $nonce = ProofPortalUnlockChallenge::current($tenantId, $invoiceId);
        if ($nonce === null) {
            abort(422, 'This unlock challenge has expired. Refresh and try again.', [
                'X-Error-Code' => 'PROOF_UNLOCK_INVALID',
            ]);
        }

        $message = ProofPortalUnlockChallenge::message(
            (string) CompanyProfile::singleton()->company_name,
            (string) $invoice->invoice_number,
            $nonce,
        );
        $recovered = EthereumPersonalSign::recoverAddress($message, $signature);
        if (
            $submitted === null
            || $recovered === null
            || ! hash_equals($submitted, $recovered)
            || ! $this->canActForBuyer($expected, $recovered)
        ) {
            abort(422, 'The connected wallet does not match this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_MISMATCH',
            ]);
        }

        ProofPortalUnlockChallenge::consume($tenantId, $invoiceId, $nonce);

        return $this->showPurchase($invoice);
    }

    public function resumePurchase(PurchaseInvoice $invoice, string $token, string $address): InvoiceProofPortalData
    {
        $this->assertPurchasePortalInvoice($invoice);
        $invoice->loadMissing('supplier');
        $this->assertSession($token, $address, ProofPortalSession::ROLE_VENDOR, $invoice->supplier?->wallet_address);

        return $this->showPurchase($invoice);
    }

    private function assertSession(string $token, string $address, string $role, mixed $expectedWallet): void
    {
        $session = ProofPortalSession::find($token);
        $submitted = WalletAddress::normalize($address);
        $expected = WalletAddress::normalize($expectedWallet);
        if (
            $session === null
            || $session['tenant_id'] !== (string) tenant('id')
            || $session['role'] !== $role
            || $submitted === null
            || ! hash_equals($session['signer'], $submitted)
        ) {
            abort(422, 'This portal session has expired. Connect your wallet again.', [
                'X-Error-Code' => 'PROOF_SESSION_INVALID',
            ]);
        }

        if ($expected === null) {
            abort(422, $role === ProofPortalSession::ROLE_VENDOR
                ? 'No vendor wallet is configured for this invoice.'
                : 'No buyer wallet is configured for this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_REQUIRED',
            ]);
        }

        if (! $this->canActForBuyer($expected, $submitted)) {
            abort(422, 'The connected wallet does not match this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_MISMATCH',
            ]);
        }
    }

    public function recordPurchaseDispute(PurchaseInvoice $invoice, string $reason, ?string $txHash = null): InvoiceProofPortalData
    {
        $this->assertPurchasePortalInvoice($invoice);
        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $this->invoiceChainRegistrationService->storeDisputeReason((string) $snapshot->id, $reason, $txHash);

        return $this->showPurchase($invoice);
    }

    public function showPurchase(PurchaseInvoice $invoice): InvoiceProofPortalData
    {
        $this->assertPurchasePortalInvoice($invoice);

        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $company = CompanyProfile::singleton();
        $invoice->loadMissing(['supplier', 'replacesInvoice', 'replacedByInvoice']);

        return InvoiceProofPortalData::fromSnapshot(
            (string) $invoice->id,
            $snapshot,
            $this->invoiceProofVerificationService->verifyPurchaseInvoice($invoice, $company),
            $this->historyItemsForSupplier((string) $invoice->supplier_id, (string) $invoice->id),
            $this->signedPurchasePortalInvoice($invoice->replacedByInvoice),
            $this->signedPurchasePortalInvoice($invoice->replacesInvoice),
            true,
        );
    }

    /**
     * @return array{locked: true, chain_id: int, nonce: string, message: string, company_name: string}
     */
    public function vendorHistoryChallenge(): array
    {
        return $this->historyChallenge();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function vendorHistory(string $address, string $signature, string $nonce): array
    {
        $this->assertProofsEnabled();
        $companyName = (string) CompanyProfile::singleton()->company_name;
        $message = InvoiceApprovalStatement::history($companyName, $nonce);
        ProofPortalHistoryChallenge::consume((string) tenant('id'), $message);

        $recovered = EthereumPersonalSign::recoverAddress($message, $signature);
        $submitted = WalletAddress::normalize($address);
        if ($submitted === null || $recovered === null || ! hash_equals($submitted, $recovered)) {
            $this->abortUnknownVendor();
        }

        $supplierIds = Supplier::query()
            ->whereRaw('lower(wallet_address) = ?', [$submitted])
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        if ($supplierIds === []) {
            $this->abortUnknownVendor();
        }

        $items = [];
        foreach ($supplierIds as $supplierId) {
            array_push($items, ...$this->historyItemsForSupplier($supplierId, null));
        }

        return $items;
    }

    /**
     * @return array{locked: true, chain_id: int, nonce: string, message: string, company_name: string}
     */
    public function historyChallenge(): array
    {
        $this->assertProofsEnabled();
        $companyName = (string) CompanyProfile::singleton()->company_name;
        $issued = ProofPortalHistoryChallenge::issue((string) tenant('id'), $companyName);

        return [
            'locked' => true,
            'chain_id' => (int) config('blockchain.chain_id'),
            'nonce' => $issued['nonce'],
            'message' => $issued['message'],
            'company_name' => $companyName,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(string $address, string $signature, string $nonce): array
    {
        $this->assertProofsEnabled();
        $companyName = (string) CompanyProfile::singleton()->company_name;
        $message = InvoiceApprovalStatement::history($companyName, $nonce);
        ProofPortalHistoryChallenge::consume((string) tenant('id'), $message);

        $recovered = EthereumPersonalSign::recoverAddress($message, $signature);
        $submitted = WalletAddress::normalize($address);
        if ($submitted === null || $recovered === null || ! hash_equals($submitted, $recovered)) {
            $this->abortUnknownBuyer();
        }

        $customerIds = Customer::query()
            ->whereRaw('lower(wallet_address) = ?', [$submitted])
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        if ($customerIds === []) {
            $this->abortUnknownBuyer();
        }

        $items = [];
        foreach ($customerIds as $customerId) {
            array_push($items, ...$this->historyItemsForCustomer($customerId, null));
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyItemsForCustomer(string $customerId, ?string $exceptInvoiceId): array
    {
        if ($customerId === '') {
            return [];
        }

        $invoices = SalesInvoice::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', [SalesInvoiceStatus::Posted, SalesInvoiceStatus::Reversed])
            ->orderByDesc('invoice_date')
            ->orderByDesc('posted_at')
            ->get();

        $company = CompanyProfile::singleton();
        $tenantId = (string) tenant('id');
        $items = [];
        foreach ($invoices as $invoice) {
            if ($exceptInvoiceId !== null && (string) $invoice->id === $exceptInvoiceId) {
                continue;
            }
            $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
            if ($snapshot === null) {
                continue;
            }
            $proof = $this->invoiceProofVerificationService->verifySalesInvoice($invoice, $company);
            $issued = ProofPortalLink::issue($tenantId, (string) $invoice->id);
            $items[] = $this->historyItem(
                (string) $invoice->id,
                $invoice->invoice_number,
                $invoice->grand_total,
                $snapshot->canonical_json,
                $proof->status->value,
                $issued,
            )->toArray();
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyItemsForSupplier(string $supplierId, ?string $exceptInvoiceId): array
    {
        if ($supplierId === '') {
            return [];
        }

        $invoices = PurchaseInvoice::query()
            ->where('supplier_id', $supplierId)
            ->whereIn('status', [PurchaseInvoiceStatus::Posted, PurchaseInvoiceStatus::Reversed])
            ->orderByDesc('invoice_date')
            ->orderByDesc('posted_at')
            ->get();

        $company = CompanyProfile::singleton();
        $tenantId = (string) tenant('id');
        $items = [];
        foreach ($invoices as $invoice) {
            if ($exceptInvoiceId !== null && (string) $invoice->id === $exceptInvoiceId) {
                continue;
            }
            $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
            if ($snapshot === null) {
                continue;
            }
            $proof = $this->invoiceProofVerificationService->verifyPurchaseInvoice($invoice, $company);
            $issued = ProofPortalLink::issue($tenantId, (string) $invoice->id, '/proofs/purchases/');
            $items[] = $this->historyItem(
                (string) $invoice->id,
                $invoice->invoice_number,
                $invoice->grand_total,
                $snapshot->canonical_json,
                $proof->status->value,
                $issued,
            )->toArray();
        }

        return $items;
    }

    /**
     * @return array{id: ?string, invoice_number: ?string, exp: ?int, sig: ?string}|null
     */
    private function signedPurchasePortalInvoice(?PurchaseInvoice $related): ?array
    {
        if ($related === null) {
            return null;
        }

        $number = is_string($related->invoice_number) && $related->invoice_number !== ''
            ? $related->invoice_number
            : null;

        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $related->id);
        if ($snapshot === null) {
            return $number === null ? null : [
                'id' => null,
                'invoice_number' => $number,
                'exp' => null,
                'sig' => null,
            ];
        }

        $issued = ProofPortalLink::issue((string) tenant('id'), (string) $related->id, '/proofs/purchases/');

        return [
            'id' => (string) $related->id,
            'invoice_number' => $number,
            'exp' => $issued['exp'],
            'sig' => $issued['sig'],
        ];
    }

    /**
     * Signed buyer-portal stamp for a related invoice, or the number only when it is still a draft.
     *
     * @return array{id: ?string, invoice_number: ?string, exp: ?int, sig: ?string}|null
     */
    private function signedPortalInvoice(?SalesInvoice $related): ?array
    {
        if ($related === null) {
            return null;
        }

        $number = is_string($related->invoice_number) && $related->invoice_number !== ''
            ? $related->invoice_number
            : null;

        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $related->id);
        if ($snapshot === null) {
            return $number === null ? null : [
                'id' => null,
                'invoice_number' => $number,
                'exp' => null,
                'sig' => null,
            ];
        }

        $issued = ProofPortalLink::issue((string) tenant('id'), (string) $related->id);

        return [
            'id' => (string) $related->id,
            'invoice_number' => $number,
            'exp' => $issued['exp'],
            'sig' => $issued['sig'],
        ];
    }

    /**
     * @param  array{url: string, exp: int, sig: string}  $issued
     */
    private function historyItem(
        string $id,
        mixed $invoiceNumber,
        mixed $grandTotal,
        string $canonicalJson,
        string $status,
        array $issued,
    ): BuyerInvoiceHistoryItemData {
        $canonical = [];

        try {
            $decoded = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $canonical = $decoded;
            }
        } catch (JsonException) {
            $canonical = [];
        }

        $number = $canonical['invoice_number'] ?? $invoiceNumber;
        $date = $canonical['invoice_date'] ?? null;
        $total = $canonical['grand_total'] ?? $grandTotal;
        $currency = $canonical['currency_code'] ?? null;

        return new BuyerInvoiceHistoryItemData(
            id: $id,
            invoiceNumber: is_string($number) && $number !== '' ? $number : null,
            invoiceDate: is_string($date) && $date !== '' ? $date : null,
            grandTotal: is_string($total) ? $total : (string) $total,
            currencyCode: is_string($currency) && $currency !== '' ? $currency : null,
            status: $status,
            exp: $issued['exp'],
            sig: $issued['sig'],
        );
    }

    private function assertProofsEnabled(): void
    {
        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(
                403,
                'Invoice proofs are disabled for this company.',
                ['X-Error-Code' => 'INVOICE_PROOFS_DISABLED']
            );
        }
    }

    private function abortUnknownBuyer(): never
    {
        abort(422, 'The connected wallet does not match a customer of this company.', [
            'X-Error-Code' => 'PROOF_BUYER_UNKNOWN',
        ]);
    }

    private function abortUnknownVendor(): never
    {
        abort(422, 'The connected wallet does not match a vendor of this company.', [
            'X-Error-Code' => 'PROOF_VENDOR_UNKNOWN',
        ]);
    }

    private function assertPurchasePortalInvoice(PurchaseInvoice $invoice): void
    {
        if (! in_array($invoice->status, [PurchaseInvoiceStatus::Posted, PurchaseInvoiceStatus::Reversed], true)) {
            abort(404);
        }

        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(
                403,
                'Invoice proofs are disabled for this company.',
                ['X-Error-Code' => 'INVOICE_PROOFS_DISABLED']
            );
        }

        $snapshot = $this->invoiceSnapshotService->findForPurchaseInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }
    }

    /**
     * Reversed invoices stay readable so an issued link shows the buyer that it was cancelled.
     */
    private function assertPortalInvoice(SalesInvoice $invoice): void
    {
        if (! in_array($invoice->status, [SalesInvoiceStatus::Posted, SalesInvoiceStatus::Reversed], true)) {
            abort(404);
        }

        if (! CompanySetting::current()->invoiceProofsEnabled()) {
            abort(
                403,
                'Invoice proofs are disabled for this company.',
                ['X-Error-Code' => 'INVOICE_PROOFS_DISABLED']
            );
        }

        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }
    }
}
