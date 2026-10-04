<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\Models;

use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_receipt_id',
    'sales_invoice_id',
    'amount',
    'applied_amount',
    'applied_exchange_rate',
])]
class CustomerReceiptAllocation extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'applied_amount' => 'decimal:4',
            'applied_exchange_rate' => 'decimal:12',
        ];
    }

    /**
     * @return BelongsTo<CustomerReceipt, $this>
     */
    public function customerReceipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class);
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }
}
