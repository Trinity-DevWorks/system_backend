<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Models\Tenant;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Notification\Support\RecipientQuery;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Services\Central\TenantCompanyWalletService;

/**
 * After a sales invoice is on chain, tell the buyer tenant when that buyer's wallet
 * is another company's Safe. The purchase invoice is created only if the buyer saves it.
 */
class TenantSalesInvoiceDelivery
{
    public function __construct(
        private readonly TenantCompanyWalletService $tenantCompanyWallets,
        private readonly InvoiceProofDisclosureService $invoiceProofDisclosureService,
        private readonly LinkedPurchaseOfferService $linkedPurchaseOfferService,
        private readonly NotificationDispatcher $notificationDispatcher,
    ) {}

    public function deliver(InvoiceSnapshot $snapshot): void
    {
        $type = $snapshot->invoice_type instanceof InvoiceProofType
            ? $snapshot->invoice_type
            : InvoiceProofType::from((string) $snapshot->invoice_type);
        if ($type !== InvoiceProofType::Sales) {
            return;
        }

        $invoice = SalesInvoice::query()->with('customer')->find($snapshot->invoice_id);
        if ($invoice === null || $invoice->customer === null) {
            return;
        }

        $buyerWallet = WalletAddress::normalize($invoice->customer->wallet_address);
        $known = $this->tenantCompanyWallets->findByWallet($buyerWallet);
        if ($known === null) {
            return;
        }

        $company = CompanyProfile::singleton();
        $sellerName = (string) $company->company_name;
        $sellerWallet = WalletAddress::normalize($company->wallet_address);
        $sellerWalletType = $company->wallet_type?->value ?? 'safe';
        $invoiceNumber = (string) ($invoice->invoice_number ?? '');
        $disclosure = $this->invoiceProofDisclosureService->discloseAll($invoice);

        $buyer = Tenant::query()->find($known->tenant_id);
        if ($buyer === null) {
            return;
        }

        $buyer->run(function () use ($disclosure, $sellerName, $sellerWallet, $sellerWalletType, $invoiceNumber): void {
            $this->receive($disclosure, $sellerName, $sellerWallet, $sellerWalletType, $invoiceNumber);
        });
    }

    /**
     * @param  array<string, mixed>  $disclosure
     */
    private function receive(
        array $disclosure,
        string $sellerName,
        ?string $sellerWallet,
        string $sellerWalletType,
        string $invoiceNumber,
    ): void {
        $proofId = strtolower(trim((string) ($disclosure['proof_id'] ?? '')));
        if ($proofId === '') {
            $this->notify(
                'purchase_invoice.needs_setup',
                $sellerName,
                $invoiceNumber,
                '/main/purchase-invoices',
                null,
                null,
            );

            return;
        }

        $existingId = PurchaseInvoice::query()->where('linked_proof_id', $proofId)->value('id');
        if ($existingId !== null) {
            $this->notify(
                'purchase_invoice.received',
                $sellerName,
                $invoiceNumber,
                '/main/purchase-invoices?drawer='.rawurlencode((string) $existingId).'&mode=edit',
                'purchase_invoice',
                (string) $existingId,
            );

            return;
        }

        $offer = $this->linkedPurchaseOfferService->store(
            $disclosure,
            $sellerName,
            $sellerWallet,
            $sellerWalletType,
            $invoiceNumber,
        );

        $this->notify(
            'purchase_invoice.received',
            $sellerName,
            $invoiceNumber,
            '/main/purchase-invoices?drawer=new&mode=create&linked_offer='.rawurlencode((string) $offer->id),
            'linked_purchase_offer',
            (string) $offer->id,
        );
    }

    private function notify(
        string $type,
        string $sellerName,
        string $invoiceNumber,
        string $actionPath,
        ?string $resourceType,
        ?string $resourceId,
    ): void {
        $this->notificationDispatcher->dispatch(
            $type,
            [
                'params' => [
                    'company_name' => $sellerName,
                    'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : '—',
                ],
                'action_path' => $actionPath,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'mail_lines' => $type === 'purchase_invoice.received'
                    ? [
                        'Invoice :invoice_number from :company_name is waiting.',
                        'Open it, check the warehouse, then save a draft or leave it.',
                    ]
                    : [
                        'Invoice :invoice_number from :company_name uses this system.',
                        'The purchase invoice was not created. Match their items and currency, then import the proof.',
                    ],
            ],
            RecipientQuery::permission('purchase_invoices', 'view'),
        );
    }
}
