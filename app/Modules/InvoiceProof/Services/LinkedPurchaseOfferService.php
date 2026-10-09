<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\InvoiceProof\Models\LinkedPurchaseOffer;
use App\Modules\InvoiceProof\Support\WalletAddress;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierService;

/**
 * Holds a supplier invoice until the buyer saves a purchase invoice for it.
 */
class LinkedPurchaseOfferService
{
    public function __construct(
        private readonly SupplierService $supplierService,
    ) {}

    /**
     * @param  array<string, mixed>  $disclosure
     */
    public function store(
        array $disclosure,
        string $sellerName,
        ?string $sellerWallet,
        string $sellerWalletType,
        string $invoiceNumber,
    ): LinkedPurchaseOffer {
        $proofId = strtolower(trim((string) ($disclosure['proof_id'] ?? '')));

        return LinkedPurchaseOffer::query()->updateOrCreate(
            ['proof_id' => $proofId],
            [
                'seller_name' => $sellerName,
                'seller_wallet' => $sellerWallet,
                'seller_wallet_type' => $sellerWalletType,
                'invoice_number' => $invoiceNumber !== '' ? $invoiceNumber : null,
                'disclosure' => $disclosure,
            ],
        );
    }

    /**
     * @return array{id: string, proof_id: string, invoice_number: ?string, company_name: string, purchase_invoice_id: ?string, disclosure: array<string, mixed>}
     */
    public function open(LinkedPurchaseOffer $offer): array
    {
        $purchaseInvoiceId = PurchaseInvoice::query()
            ->where('linked_proof_id', $offer->proof_id)
            ->value('id');

        if ($purchaseInvoiceId === null) {
            $this->ensureSupplier($offer);
        }

        return [
            'id' => (string) $offer->id,
            'proof_id' => (string) $offer->proof_id,
            'invoice_number' => $offer->invoice_number,
            'company_name' => (string) $offer->seller_name,
            'purchase_invoice_id' => $purchaseInvoiceId !== null ? (string) $purchaseInvoiceId : null,
            'disclosure' => is_array($offer->disclosure) ? $offer->disclosure : [],
        ];
    }

    /**
     * The form needs a supplier row to fill. The purchase invoice is still unsaved.
     */
    private function ensureSupplier(LinkedPurchaseOffer $offer): void
    {
        $wallet = WalletAddress::normalize($offer->seller_wallet);
        if ($wallet === null) {
            return;
        }

        if (Supplier::query()->where('wallet_address', $wallet)->where('is_active', true)->exists()) {
            return;
        }

        $this->supplierService->create([
            'name' => (string) $offer->seller_name,
            'company_name' => (string) $offer->seller_name,
            'wallet_address' => $wallet,
            'wallet_type' => (string) ($offer->seller_wallet_type ?: 'safe'),
            'is_active' => true,
        ]);
    }
}
