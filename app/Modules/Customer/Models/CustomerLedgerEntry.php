<?php

declare(strict_types=1);

namespace App\Modules\Customer\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Enums\LedgerReferenceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'customer_id',
    'currency_id',
    'debit',
    'credit',
    'reference_type',
    'reference_id',
    'transaction_date',
])]
class CustomerLedgerEntry extends Model
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
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
