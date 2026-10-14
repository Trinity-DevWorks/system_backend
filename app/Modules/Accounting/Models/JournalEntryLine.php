<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use App\Modules\Currency\Models\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'journal_entry_id',
    'account_id',
    'debit',
    'credit',
    'currency_id',
    'exchange_rate',
    'amount_currency',
    'party_type',
    'party_id',
    'description',
    'sort_order',
])]
class JournalEntryLine extends Model
{
    public const PARTY_CUSTOMER = 'customer';

    public const PARTY_SUPPLIER = 'supplier';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'currency_id' => 'integer',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'exchange_rate' => 'decimal:12',
            'amount_currency' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
