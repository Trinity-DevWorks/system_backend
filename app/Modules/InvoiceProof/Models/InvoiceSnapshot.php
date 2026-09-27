<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Immutable posted-invoice proof: canonical JSON plus SHA-256. Insert-only; no update or delete.
 *
 * @property InvoiceProofType $invoice_type
 */
#[Fillable([
    'id',
    'invoice_type',
    'invoice_id',
    'schema_version',
    'canonical_json',
    'content_hash',
])]
class InvoiceSnapshot extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_type' => InvoiceProofType::class,
            'schema_version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new RuntimeException('Invoice snapshots are immutable and cannot be updated.');
        });

        static::deleting(function (): never {
            throw new RuntimeException('Invoice snapshots are immutable and cannot be deleted.');
        });
    }
}
