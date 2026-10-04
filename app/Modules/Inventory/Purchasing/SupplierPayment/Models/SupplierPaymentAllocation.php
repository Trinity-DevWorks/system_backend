<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\Models;

use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_payment_id',
    'purchase_invoice_id',
    'amount',
    'applied_amount',
    'applied_exchange_rate',
])]
class SupplierPaymentAllocation extends Model
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
     * @return BelongsTo<SupplierPayment, $this>
     */
    public function supplierPayment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class);
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }
}
