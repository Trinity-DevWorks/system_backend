<?php

declare(strict_types=1);

namespace App\Modules\Inventory\PriceChange\Models;

use App\Models\User;
use App\Modules\Inventory\PriceChange\Enums\PriceChangeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string $id
 * @property string|null $number
 * @property Carbon $effective_date
 * @property PriceChangeStatus $status
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $applied_by
 * @property Carbon|null $applied_at
 * @property string|null $cancelled_by
 * @property Carbon|null $cancelled_at
 */
#[Fillable([
    'number',
    'effective_date',
    'status',
    'notes',
    'created_by',
    'applied_by',
    'applied_at',
    'cancelled_by',
    'cancelled_at',
])]
class PriceChange extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'status' => PriceChangeStatus::class,
            'applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PriceChangeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PriceChangeLine::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function applier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
