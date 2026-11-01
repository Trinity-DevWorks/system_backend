<?php

declare(strict_types=1);

namespace App\Modules\Branch\Models;

use App\Models\User;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property-read BranchUser|null $pivot
 * @property int|null $price_list_id
 */
#[Fillable([
    'name',
    'shortcut_name',
    'address',
    'phone',
    'email',
    'timezone',
    'opening_time',
    'closing_time',
    'manager_id',
    'is_active',
    'is_default',
    'price_list_id',
])]
class Branch extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * @return HasMany<Warehouse, $this>
     */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /**
     * @return HasMany<Salesman, $this>
     */
    public function salesmen(): HasMany
    {
        return $this->hasMany(Salesman::class);
    }

    /**
     * @return BelongsToMany<User, $this, BranchUser>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user')
            ->using(BranchUser::class)
            ->withPivot('role_id')
            ->withTimestamps();
    }

    /**
     * Head office: the default branch, or the first one. Company-level journal entries
     * (opening balances, VAT settlement) are booked here so branch reports include them.
     */
    public static function defaultId(): ?int
    {
        $id = self::query()->orderByDesc('is_default')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }
}
