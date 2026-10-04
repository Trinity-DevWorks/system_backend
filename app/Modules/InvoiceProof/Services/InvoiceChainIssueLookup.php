<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Services;

use App\Models\User;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\InvoiceProof\Enums\InvoiceChainCheckStatus;
use App\Modules\InvoiceProof\Enums\InvoiceChainIssueKind;
use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Models\InvoiceChainCheck;
use App\Modules\InvoiceProof\Models\InvoiceChainCheckIssue;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Auth;

/**
 * Per-invoice view of the latest chain consistency check, for badges on invoice lists and drawers.
 */
class InvoiceChainIssueLookup
{
    public function __construct(
        private readonly PermissionService $permissionService,
    ) {}

    /**
     * Most severe open issue per invoice. Empty when invoice proofs are disabled
     * or the current user cannot read invoice proofs.
     *
     * @param  iterable<mixed>  $invoiceIds
     * @return array<string, array{kind: string, checked_at: string|null}>
     */
    public function forInvoices(iterable $invoiceIds): array
    {
        $ids = collect($invoiceIds)->map(fn (mixed $id): string => (string) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || ! $this->visible()) {
            return [];
        }

        $check = InvoiceChainCheck::query()->latest('started_at')->latest('id')->first(['id', 'status', 'finished_at']);
        if ($check === null || $check->status !== InvoiceChainCheckStatus::Issues) {
            return [];
        }

        $issues = InvoiceChainCheckIssue::query()
            ->where('check_id', $check->id)
            ->whereIn('invoice_id', $ids)
            ->get(['invoice_id', 'proof_id', 'kind']);
        if ($issues->isEmpty()) {
            return [];
        }

        $fixedProofIds = InvoiceChainRegistration::query()
            ->whereIn('proof_id', $issues->filter(fn (InvoiceChainCheckIssue $issue): bool => $issue->kind->resolvedByRegistration())->pluck('proof_id'))
            ->where('status', InvoiceChainRegistrationStatus::Confirmed)
            ->pluck('proof_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->flip();

        $revokedProofIds = InvoiceChainRegistration::query()
            ->whereIn('proof_id', $issues->filter(fn (InvoiceChainCheckIssue $issue): bool => $issue->kind->resolvedByRevocation())->pluck('proof_id'))
            ->whereNotNull('revoked_at')
            ->pluck('proof_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->flip();

        $severity = array_flip(array_map(fn (InvoiceChainIssueKind $kind): string => $kind->value, InvoiceChainIssueKind::cases()));
        $checkedAt = $check->finished_at?->toIso8601String();
        $result = [];

        foreach ($issues as $issue) {
            if ($issue->kind->resolvedByRegistration() && $fixedProofIds->has((string) $issue->proof_id)) {
                continue;
            }
            if ($issue->kind->resolvedByRevocation() && $revokedProofIds->has((string) $issue->proof_id)) {
                continue;
            }

            $invoiceId = (string) $issue->invoice_id;
            $current = $result[$invoiceId]['kind'] ?? null;
            if ($current === null || $severity[$issue->kind->value] < $severity[$current]) {
                $result[$invoiceId] = ['kind' => $issue->kind->value, 'checked_at' => $checkedAt];
            }
        }

        return $result;
    }

    /**
     * Invoice proofs are enabled and the current user may read them.
     */
    public function visible(): bool
    {
        $user = Auth::user();
        if (! $user instanceof User || ! CompanySetting::current()->invoiceProofsEnabled()) {
            return false;
        }

        return $this->permissionService->userHas('invoice_proofs', 'view', $user)
            || $this->permissionService->userHas('invoice_proofs', 'edit', $user);
    }
}
