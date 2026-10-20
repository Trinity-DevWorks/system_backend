<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Models;

use App\Models\User;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sales_credit_note_id',
    'sales_invoice_id',
    'amount',
    'is_automatic',
    'created_by',
])]
class SalesCreditNoteAllocation extends Model
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
     * @return BelongsTo<SalesCreditNote, $this>
     */
    public function salesCreditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class);
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
