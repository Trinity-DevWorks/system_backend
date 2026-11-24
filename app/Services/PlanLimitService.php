<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Models\Central\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\TenantReferenceCache;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

/**
 * Enforces the current tenant's plan limits (runs inside tenancy). Null limits are unlimited;
 * a tenant without a plan is unlimited too.
 *
 * Counted: active users, branches, warehouses, and the bytes of non-deleted attachments.
 */
final class PlanLimitService
{
    public const CACHE_KEY = 'plan.limits';

    public const ERROR_CODE = 'PLAN_LIMIT_REACHED';

    private const BYTES_PER_MB = 1048576;

    /**
     * @return array{plan: array{id: int, name: string, price: string, currency: string, billing_cycle: string}|null, limits: array<string, int|null>, subscription_starts_at: string|null, subscription_ends_at: string|null, trial_ends_at: string|null}
     */
    public function current(): array
    {
        $tenantId = (string) tenant('id');

        /** @var array{plan: array{id: int, name: string, price: string, currency: string, billing_cycle: string}|null, limits: array<string, int|null>, subscription_starts_at: string|null, subscription_ends_at: string|null, trial_ends_at: string|null} */
        return Cache::remember(
            TenantReferenceCache::scopedForTenant($tenantId, self::CACHE_KEY),
            TenantReferenceCache::ttlSeconds(),
            function () use ($tenantId): array {
                $tenant = Tenant::query()->with('plan')->find($tenantId);
                $plan = $tenant?->plan;

                return [
                    'plan' => $plan instanceof Plan ? [
                        'id' => (int) $plan->id,
                        'name' => (string) $plan->name,
                        'price' => (string) $plan->price,
                        'currency' => (string) $plan->currency,
                        'billing_cycle' => $plan->billing_cycle->value,
                    ] : null,
                    'limits' => array_combine(
                        Plan::LIMITS,
                        array_map(fn (string $key): ?int => $plan?->limit($key), Plan::LIMITS)
                    ),
                    'subscription_starts_at' => $tenant?->subscription_starts_at?->toDateString(),
                    'subscription_ends_at' => $tenant?->subscription_ends_at?->toDateString(),
                    'trial_ends_at' => $tenant?->trial_ends_at?->toDateString(),
                ];
            }
        );
    }

    /**
     * Current usage per limit key (storage in MB, rounded up).
     *
     * @return array<string, int>
     */
    public function usage(): array
    {
        return [
            'users' => $this->used('users'),
            'branches' => $this->used('branches'),
            'warehouses' => $this->used('warehouses'),
            'storage_mb' => (int) ceil($this->storageBytes() / self::BYTES_PER_MB),
        ];
    }

    /**
     * Throws 422 PLAN_LIMIT_REACHED when one more user / branch / warehouse would exceed the plan.
     */
    public function assertCanAdd(string $limit): void
    {
        $max = $this->current()['limits'][$limit] ?? null;
        if ($max === null) {
            return;
        }

        $used = $this->used($limit);
        if ($used + 1 > $max) {
            $this->fail($limit, $max, $used);
        }
    }

    /**
     * Throws 422 PLAN_LIMIT_REACHED when storing `$bytes` more would exceed the storage limit.
     */
    public function assertCanStore(int $bytes): void
    {
        $maxMb = $this->current()['limits']['storage_mb'] ?? null;
        if ($maxMb === null) {
            return;
        }

        $usedBytes = $this->storageBytes();
        if ($usedBytes + $bytes > $maxMb * self::BYTES_PER_MB) {
            $this->fail('storage_mb', $maxMb, (int) ceil($usedBytes / self::BYTES_PER_MB));
        }
    }

    private function used(string $limit): int
    {
        return match ($limit) {
            'users' => User::query()->where('is_active', true)->count(),
            'branches' => Branch::query()->count(),
            'warehouses' => Warehouse::query()->count(),
            default => 0,
        };
    }

    private function storageBytes(): int
    {
        return (int) Attachment::query()->sum('file_size');
    }

    private function fail(string $limit, int $max, int $used): never
    {
        $message = match ($limit) {
            'users' => "Your plan allows up to {$max} active users.",
            'branches' => "Your plan allows up to {$max} branches.",
            'warehouses' => "Your plan allows up to {$max} warehouses.",
            default => "Your plan allows up to {$max} MB of file storage.",
        };

        $values = ['limit' => $limit, 'max' => $max, 'used' => $used];

        throw new HttpResponseException(ApiResponse::error(
            $message,
            422,
            // `params` feed the localized client message (ApiErrors.codes.PLAN_LIMIT_REACHED).
            [...$values, 'params' => $values],
            [],
            null,
            null,
            self::ERROR_CODE,
        ));
    }
}
