<?php

declare(strict_types=1);

namespace App\Modules\InvoiceProof\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A supplier sales invoice waiting in the buyer tenant.
 * Opening it fills a purchase invoice. Saving is what creates the document.
 *
 * @property array<string, mixed> $disclosure
 */
#[Fillable([
    'proof_id',
    'seller_name',
    'seller_wallet',
    'seller_wallet_type',
    'invoice_number',
    'disclosure',
])]
class LinkedPurchaseOffer extends Model
{
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'disclosure' => 'array',
        ];
    }
}
