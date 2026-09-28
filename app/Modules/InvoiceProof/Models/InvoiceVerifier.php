<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use App\Modules\InvoiceProof\Enums\InvoiceVerifierChainStatus;
use App\Modules\InvoiceProof\Enums\InvoiceVerifierRole;
use App\Modules\InvoiceProof\Enums\WalletType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Bank, auditor, or tax authority wallet the company allows to attest its invoices.
 * The registrar mirrors each row on InvoiceRegistry for the company Safe.
 *
 * @property InvoiceVerifierRole $role
 * @property InvoiceVerifierChainStatus $chain_status
 * @property WalletType $wallet_type
 */
#[Fillable([
    'name',
    'role',
    'wallet_address',
    'wallet_type',
    'notes',
    'chain_status',
    'chain_company_wallet',
    'chain_tx_hash',
    'chain_error',
    'chain_synced_at',
])]
class InvoiceVerifier extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => InvoiceVerifierRole::class,
            'chain_status' => InvoiceVerifierChainStatus::class,
            'wallet_type' => WalletType::class,
            'chain_synced_at' => 'datetime',
        ];
    }
}
