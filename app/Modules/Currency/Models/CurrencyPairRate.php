<?php

declare(strict_types=1);

namespace App\Modules\Currency\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $effective_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CurrencyPairRate extends Model
{
    protected $fillable = [
        'from_currency_id',
        'to_currency_id',
        'rate',
        'effective_from',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'effective_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency_id');
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency_id');
    }
}
