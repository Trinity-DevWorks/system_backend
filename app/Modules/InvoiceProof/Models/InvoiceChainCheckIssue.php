<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use App\Modules\InvoiceProof\Enums\InvoiceChainIssueKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One disagreement found by a consistency check. `expected` is the ERP side,
 * `actual` is what the chain (or the recomputed snapshot hash) shows.
 *
 * @property InvoiceChainIssueKind $kind
 */
#[Fillable([
    'check_id',
    'kind',
    'proof_id',
    'chain_proof_id',
    'invoice_id',
    'invoice_number',
    'expected',
    'actual',
])]
class InvoiceChainCheckIssue extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => InvoiceChainIssueKind::class,
        ];
    }
}
