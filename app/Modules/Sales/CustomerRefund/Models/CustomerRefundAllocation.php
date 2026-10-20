<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerRefund\Models;

use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_refund_id',
    'sales_credit_note_id',
    'amount',
    'applied_amount',
    'applied_exchange_rate',
    'exchange_difference',
])]
class CustomerRefundAllocation extends Model
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
            'exchange_difference' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<CustomerRefund, $this>
     */
    public function customerRefund(): BelongsTo
    {
        return $this->belongsTo(CustomerRefund::class);
    }

    /**
     * @return BelongsTo<SalesCreditNote, $this>
     */
    public function salesCreditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class);
    }
}
