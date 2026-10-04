<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Enums\InvoiceProofVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Mutable on-chain registration record for an immutable invoice snapshot.
 *
 * @property InvoiceProofType $invoice_type
 * @property InvoiceChainRegistrationStatus $status
 * @property InvoiceProofVerificationStatus|null $chain_status last status read from the contract
 * @property Carbon|null $revoke_requested_at set when the ERP cancelled the invoice
 * @property Carbon|null $revoked_at set once revokeInvoice is confirmed on chain
 */
#[Fillable([
    'id',
    'proof_id',
    'invoice_type',
    'invoice_id',
    'status',
    'tx_hash',
    'block_number',
    'contract_address',
    'last_error',
    'chain_status',
    'financed_at',
    'status_checked_at',
    'revoke_requested_at',
    'revoked_at',
    'revoke_tx_hash',
    'revoke_error',
])]
class InvoiceChainRegistration extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_type' => InvoiceProofType::class,
            'status' => InvoiceChainRegistrationStatus::class,
            'block_number' => 'integer',
            'chain_status' => InvoiceProofVerificationStatus::class,
            'financed_at' => 'datetime',
            'status_checked_at' => 'datetime',
            'revoke_requested_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<InvoiceSnapshot, $this>
     */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(InvoiceSnapshot::class, 'proof_id');
    }
}
