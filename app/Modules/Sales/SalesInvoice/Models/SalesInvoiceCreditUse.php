<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Customer credit kept on one sales invoice (paid above its total) used on another invoice.
 *
 * @property int $id
 * @property string $source_invoice_id
 * @property string $sales_invoice_id
 * @property string $amount in the source invoice currency
 * @property string $applied_amount in the target invoice currency
 * @property string $applied_exchange_rate
 * @property string $exchange_difference
 * @property bool $at_sale used while posting the target invoice (posted in its entry)
 * @property Carbon|null $applied_at
 */
#[Fillable([
    'source_invoice_id',
    'sales_invoice_id',
    'amount',
    'applied_amount',
    'applied_exchange_rate',
    'exchange_difference',
    'at_sale',
    'applied_at',
])]
class SalesInvoiceCreditUse extends Model
{
    public const REFERENCE_TYPE = 'sales_invoice_credit_use';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'applied_exchange_rate' => 'decimal:12',
            'exchange_difference' => 'decimal:4',
            'at_sale' => 'boolean',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'source_invoice_id');
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
