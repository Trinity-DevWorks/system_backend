<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use App\Modules\InvoiceProof\Enums\InvoiceChainCheckStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One run of the chain-versus-database consistency check.
 *
 * @property InvoiceChainCheckStatus $status
 * @property Carbon $started_at
 * @property Carbon $finished_at
 */
#[Fillable([
    'status',
    'blockchain_network',
    'contract_address',
    'checked_count',
    'issue_count',
    'requeued_count',
    'scanned_supplier',
    'scanned_from_block',
    'scanned_to_block',
    'error',
    'started_at',
    'finished_at',
])]
class InvoiceChainCheck extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceChainCheckStatus::class,
            'checked_count' => 'integer',
            'issue_count' => 'integer',
            'requeued_count' => 'integer',
            'scanned_from_block' => 'integer',
            'scanned_to_block' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<InvoiceChainCheckIssue, $this>
     */
    public function issues(): HasMany
    {
        return $this->hasMany(InvoiceChainCheckIssue::class, 'check_id');
    }
}
