<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Models\Tenant;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Inventory\Purchasing\Services\PurchaseInvoiceService;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Models\InvoiceSnapshot;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Notification\Services\NotificationDispatcher;
use App\Modules\Notification\Support\RecipientQuery;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\Central\TenantCompanyWalletService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After a sales invoice is on chain, open a draft purchase invoice in the buyer tenant
 * when that buyer's wallet is another company's Safe.
 */
class TenantSalesInvoiceDelivery
{
    public function __construct(
        private readonly TenantCompanyWalletService $tenantCompanyWallets,
        private readonly InvoiceProofDisclosureService $invoiceProofDisclosureService,
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
        try {
            $purchase = $this->createDraft($disclosure, $sellerName, $sellerWallet, $sellerWalletType);
        } catch (Throwable $exception) {
            Log::warning('Could not open a purchase invoice for a known buyer tenant.', [
                'message' => $exception->getMessage(),
            ]);
            $this->notify(
                'purchase_invoice.needs_setup',
                $sellerName,
                $invoiceNumber,
                null,
                null,
            );

            return;
        }

        $this->notify(
            'purchase_invoice.received',
            $sellerName,
            $invoiceNumber,
            (string) $purchase->id,
            (string) ($purchase->invoice_number ?? ''),
        );
    }

    /**
     * @param  array<string, mixed>  $disclosure
     */
    private function createDraft(
        array $disclosure,
        string $sellerName,
        ?string $sellerWallet,
        string $sellerWalletType,
    ): \App\Modules\Inventory\Purchasing\Models\PurchaseInvoice {
        if ($sellerWallet !== null && ! Supplier::query()->where('wallet_address', $sellerWallet)->where('is_active', true)->exists()) {
            app(SupplierService::class)->create([
                'name' => $sellerName,
                'company_name' => $sellerName,
                'wallet_address' => $sellerWallet,
                'wallet_type' => $sellerWalletType,
                'is_active' => true,
            ]);
        }

        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->orderByDesc('is_default_purchase')
            ->orderBy('id')
            ->first();
        if ($warehouse === null) {
            throw new \RuntimeException('The buyer tenant has no warehouse.');
        }

        $form = app(LinkedPurchaseDisclosureService::class)->import($disclosure);
        $lines = [];
        foreach ($form['lines'] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $line['warehouse_id'] = (int) $warehouse->id;
            $lines[] = $line;
        }

        return app(PurchaseInvoiceService::class)->create([
            'supplier_id' => $form['supplier_id'],
            'warehouse_id' => (int) $warehouse->id,
            'currency_id' => $form['currency_id'],
            'invoice_date' => $form['invoice_date'],
            'due_on' => $form['due_on'],
            'exchange_rate' => $form['exchange_rate'],
            'adjustment' => $form['adjustment'],
            'notes' => $form['notes'],
            'linked_proof_id' => $form['proof_id'],
            'disclosure' => $disclosure,
            'lines' => $lines,
        ], null);
    }

    private function notify(
        string $type,
        string $sellerName,
        string $invoiceNumber,
        ?string $purchaseInvoiceId,
        ?string $purchaseNumber,
    ): void {
        $this->notificationDispatcher->dispatch(
            $type,
            [
                'params' => [
                    'company_name' => $sellerName,
                    'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : '—',
                    'purchase_number' => $purchaseNumber ?? '',
                ],
                'action_path' => $purchaseInvoiceId
                    ? '/main/purchase-invoices?drawer='.rawurlencode($purchaseInvoiceId).'&mode=edit'
                    : '/main/purchase-invoices',
                'resource_type' => 'purchase_invoice',
                'resource_id' => $purchaseInvoiceId,
                'mail_lines' => $type === 'purchase_invoice.received'
                    ? [
                        'Invoice :invoice_number from :company_name is ready as purchase invoice :purchase_number.',
                        'Choose the warehouse if needed, then post it.',
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
