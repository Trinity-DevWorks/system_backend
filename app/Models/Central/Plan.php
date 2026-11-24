<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Enums\BillingCycle;
use App\Models\Module;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Subscription plan: the modules a tenant gets, its limits (null = unlimited) and its price.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $price
 * @property string $currency
 * @property BillingCycle $billing_cycle
 * @property int|null $max_users
 * @property int|null $max_branches
 * @property int|null $max_warehouses
 * @property int|null $max_storage_mb
 * @property bool $is_active
 * @property int $sort_order
 */
#[Fillable([
    'name',
    'description',
    'price',
    'currency',
    'billing_cycle',
    'max_users',
    'max_branches',
    'max_warehouses',
    'max_storage_mb',
    'is_active',
    'sort_order',
])]
class Plan extends Model implements AuditableContract
{
    use Auditable;
    use CentralConnection;

    /** Limit keys, each stored as `max_<key>` (storage in MB). */
    public const LIMITS = ['users', 'branches', 'warehouses', 'storage_mb'];

    public const DEFAULT_PLAN_NAME = 'Unlimited';

    protected $table = 'plans';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'billing_cycle' => BillingCycle::class,
            'max_users' => 'integer',
            'max_branches' => 'integer',
            'max_warehouses' => 'integer',
            'max_storage_mb' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Module, $this>
     */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'plan_modules', 'plan_id', 'module_code', 'id', 'code')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Tenant, $this>
     */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'plan_id');
    }

    /**
     * @return list<string>
     */
    public function moduleCodes(): array
    {
        return $this->modules->pluck('code')->map(fn ($c): string => (string) $c)->sort()->values()->all();
    }

    public function limit(string $key): ?int
    {
        $value = $this->getAttribute('max_'.$key);

        return $value !== null ? (int) $value : null;
    }
}
