<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TenantStatus;
use App\Models\Central\Plan;
use App\Models\Central\TenantUsageSnapshot;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * @property int|null $plan_id
 * @property Carbon|null $subscription_starts_at
 * @property Carbon|null $subscription_ends_at
 * @property Carbon|null $trial_ends_at
 */
class Tenant extends BaseTenant implements AuditableContract, TenantWithDatabase
{
    use Auditable;
    use HasDatabase;
    use HasDomains;

    protected $fillable = [
        'id',
        'name',
        'status',
        'suspended_at',
        'suspension_reason',
        'plan_id',
        'subscription_starts_at',
        'subscription_ends_at',
        'trial_ends_at',
        'data',
    ];

    /**
     * Real columns; everything else is stored in the `data` JSON column by stancl.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'status',
            'suspended_at',
            'suspension_reason',
            'plan_id',
            'subscription_starts_at',
            'subscription_ends_at',
            'trial_ends_at',
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'suspended_at' => 'datetime',
            'plan_id' => 'integer',
            'subscription_starts_at' => 'date',
            'subscription_ends_at' => 'date',
            'trial_ends_at' => 'date',
        ];
    }

    public function isSuspended(): bool
    {
        return $this->status === TenantStatus::Suspended;
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * @return HasOne<TenantUsageSnapshot, $this>
     */
    public function usage(): HasOne
    {
        return $this->hasOne(TenantUsageSnapshot::class, 'tenant_id');
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(
            Module::class,
            'tenant_modules',
            'tenant_id',
            'module_code',
            'id',
            'code'
        )->withTimestamps();
    }
}
