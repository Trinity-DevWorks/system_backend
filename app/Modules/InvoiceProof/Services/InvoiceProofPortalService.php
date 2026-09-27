<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Customer\Models\Customer;
use App\Modules\InvoiceProof\DTOs\BuyerInvoiceHistoryItemData;
use App\Modules\InvoiceProof\DTOs\BuyerPortalLinkData;
use App\Modules\InvoiceProof\DTOs\InvoiceProofPortalChallengeData;
use App\Modules\InvoiceProof\DTOs\InvoiceProofPortalData;
use App\Modules\InvoiceProof\Support\EthereumPersonalSign;
use App\Modules\InvoiceProof\Support\ProofPortalHistoryChallenge;
use App\Modules\InvoiceProof\Support\ProofPortalLink;
use App\Modules\InvoiceProof\Support\ProofPortalUnlockChallenge;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use JsonException;

/**
 * Public buyer-portal lookup. HMAC exp+sig is the capability; UUID alone is not.
 * GET returns a locked personal_sign challenge. Commercial fields come from the
 * sealed snapshot after POST unlock with the customer wallet.
 */
class InvoiceProofPortalService
{
    public function __construct(
        private readonly InvoiceSnapshotService $invoiceSnapshotService,
        private readonly InvoiceProofVerificationService $invoiceProofVerificationService,
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

        return new InvoiceProofPortalChallengeData(
            locked: true,
            chainId: (int) config('blockchain.chain_id'),
            buyerWallet: WalletAddress::normalize($invoice->customer?->wallet_address),
            nonce: $issued['nonce'],
            message: $issued['message'],
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
            || ! hash_equals($expected, $submitted)
            || ! hash_equals($expected, $recovered)
        ) {
            abort(422, 'The connected wallet does not match this invoice.', [
                'X-Error-Code' => 'PROOF_WALLET_MISMATCH',
            ]);
        }

        ProofPortalUnlockChallenge::consume($tenantId, $invoiceId, $nonce);

        return $this->show($invoice);
    }

    public function show(SalesInvoice $invoice): InvoiceProofPortalData
    {
        $this->assertPortalInvoice($invoice);

        $snapshot = $this->invoiceSnapshotService->findForSalesInvoice((string) $invoice->id);
        if ($snapshot === null) {
            abort(404);
        }

        $company = CompanyProfile::singleton();

        return InvoiceProofPortalData::fromSnapshot(
            $invoice,
            $snapshot,
            $this->invoiceProofVerificationService->verifySalesInvoice($invoice, $company),
            $this->historyItemsForCustomer((string) $invoice->customer_id, (string) $invoice->id),
        );
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
        $message = \App\Modules\InvoiceProof\Support\InvoiceApprovalStatement::history($companyName, $nonce);
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
            ->where('status', SalesInvoiceStatus::Posted)
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
            $items[] = $this->historyItem($invoice, $snapshot->canonical_json, $proof->status->value, $issued)->toArray();
        }

        return $items;
    }

    /**
     * @param  array{url: string, exp: int, sig: string}  $issued
     */
    private function historyItem(SalesInvoice $invoice, string $canonicalJson, string $status, array $issued): BuyerInvoiceHistoryItemData
    {
        $canonical = [];
        try {
            $decoded = json_decode($canonicalJson, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $canonical = $decoded;
            }
        } catch (JsonException) {
            $canonical = [];
        }

        $number = $canonical['invoice_number'] ?? $invoice->invoice_number;
        $date = $canonical['invoice_date'] ?? null;
        $total = $canonical['grand_total'] ?? $invoice->grand_total;
        $currency = $canonical['currency_code'] ?? null;

        return new BuyerInvoiceHistoryItemData(
            id: (string) $invoice->id,
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

    private function assertPortalInvoice(SalesInvoice $invoice): void
    {
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
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
