<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models;

use App\Models\User;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'purchase_debit_note_id',
    'purchase_invoice_id',
    'amount',
    'is_automatic',
    'created_by',
])]
class PurchaseDebitNoteAllocation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'is_automatic' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PurchaseDebitNote, $this>
     */
    public function purchaseDebitNote(): BelongsTo
    {
        return $this->belongsTo(PurchaseDebitNote::class);
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
