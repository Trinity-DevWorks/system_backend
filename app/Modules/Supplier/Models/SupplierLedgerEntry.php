<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use App\Modules\Supplier\Enums\LedgerReferenceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'supplier_id',
    'debit',
    'credit',
    'reference_type',
    'reference_id',
    'transaction_date',
])]
class SupplierLedgerEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'reference_type' => LedgerReferenceType::class,
            'transaction_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
