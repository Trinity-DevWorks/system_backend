<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use App\Modules\InvoiceProof\Enums\InvoiceChainRegistrationStatus;
use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mutable on-chain registration record for an immutable invoice snapshot.
 *
 * @property InvoiceProofType $invoice_type
 * @property InvoiceChainRegistrationStatus $status
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
