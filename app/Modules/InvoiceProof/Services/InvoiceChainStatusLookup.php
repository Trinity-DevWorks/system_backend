<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;

/**
 * Per-invoice blockchain status for invoice lists and drawers, from the stored registration
 * rows. No RPC call: the status is the last one read by verify, the portal, or the chain check.
 */
class InvoiceChainStatusLookup
{
    public const FAILED = 'failed';

    public function __construct(
        private readonly InvoiceChainIssueLookup $invoiceChainIssueLookup,
    ) {}

    /**
     * Empty when invoice proofs are disabled or the current user cannot read invoice proofs.
     * Invoices never submitted to the chain have no entry.
     *
     * @param  iterable<mixed>  $invoiceIds
     * @return array<string, array{status: string, financed: bool, checked_at: string|null}>
     */
    public function forSalesInvoices(iterable $invoiceIds): array
    {
        $ids = collect($invoiceIds)->map(fn (mixed $id): string => (string) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || ! $this->invoiceChainIssueLookup->visible()) {
            return [];
        }

        $registrations = InvoiceChainRegistration::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->whereIn('invoice_id', $ids)
            ->orderBy('created_at')
            ->get(['invoice_id', 'status', 'chain_status', 'financed_at', 'status_checked_at', 'updated_at']);

        $result = [];
        foreach ($registrations as $registration) {
            $status = match ($registration->status) {
                InvoiceChainRegistrationStatus::Pending => InvoiceProofVerificationStatus::PendingChain->value,
                InvoiceChainRegistrationStatus::Failed => self::FAILED,
                InvoiceChainRegistrationStatus::Confirmed => ($registration->chain_status ?? InvoiceProofVerificationStatus::WaitingCompany)->value,
            };

            $result[(string) $registration->invoice_id] = [
                'status' => $status,
                'financed' => $registration->status === InvoiceChainRegistrationStatus::Confirmed
                    && $registration->financed_at !== null,
                'checked_at' => ($registration->status_checked_at ?? $registration->updated_at)?->toIso8601String(),
            ];
        }

        return $result;
    }
}
