<?php

declare(strict_types=1);

namespace App\Modules\Sales\PriceList\Models;

use App\Modules\Currency\Models\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property ?string $name_ar
 * @property int $currency_id
 * @property ?Carbon $valid_from
 * @property ?Carbon $valid_to
 * @property bool $is_default
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'name_ar', 'currency_id', 'valid_from', 'valid_to', 'is_default', 'is_active'])]
class PriceList extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * @return HasMany<PriceListItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /**
     * Active lists whose validity window contains $date (open-ended bounds allowed).
     *
     * @param  Builder<PriceList>  $query
     * @return Builder<PriceList>
     */
    public function scopeUsableOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhereDate('valid_from', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $date));
    }
}
