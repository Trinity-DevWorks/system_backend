<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\Models;

use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_refund_id',
    'purchase_debit_note_id',
    'amount',
    'applied_amount',
    'applied_exchange_rate',
    'exchange_difference',
])]
class SupplierRefundAllocation extends Model
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
     * @return BelongsTo<SupplierRefund, $this>
     */
    public function supplierRefund(): BelongsTo
    {
        return $this->belongsTo(SupplierRefund::class);
    }

    /**
     * @return BelongsTo<PurchaseDebitNote, $this>
     */
    public function purchaseDebitNote(): BelongsTo
    {
        return $this->belongsTo(PurchaseDebitNote::class);
    }
}
