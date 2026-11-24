<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Enums\TenantStatus;
use App\Models\Central\Plan;
use App\Models\Tenant;
use App\Services\PlanLimitService;
use App\Support\TenantReferenceCache;
use Illuminate\Support\Carbon;

/**
 * A tenant's plan and subscription period. The plan decides the tenant's modules;
 * an ended subscription suspends the tenant (daily `tenants:expire-subscriptions`).
 */
final class TenantSubscriptionService
{
    public const EXPIRED_REASON = 'Subscription expired';

    public function __construct(
        private readonly TenantAdminService $tenants,
    ) {}

    /**
     * `$endsAt` null = no end date. Defaults: starts today.
     */
    public function assign(Tenant $tenant, Plan $plan, ?Carbon $startsAt, ?Carbon $endsAt, ?Carbon $trialEndsAt): Tenant
    {
        $tenant->update([
            'plan_id' => $plan->id,
            'subscription_starts_at' => ($startsAt ?? now())->toDateString(),
            'subscription_ends_at' => $endsAt?->toDateString(),
            'trial_ends_at' => $trialEndsAt?->toDateString(),
        ]);

        $this->syncModulesFromPlan($tenant, $plan);
        $this->forgetLimitsCache($tenant);
        CentralOverviewService::forget();

        return $tenant->refresh()->load(['domains', 'plan']);
    }

    /**
     * End of one billing period from `$startsAt` (used when creating a tenant without an end date).
     */
    public function defaultEnd(Plan $plan, Carbon $startsAt): Carbon
    {
        return $plan->billing_cycle->periodEnd($startsAt);
    }

    /**
     * Makes the tenant's modules equal to its plan's (audited as `modules_updated` when they change).
     */
    public function syncModulesFromPlan(Tenant $tenant, Plan $plan): void
    {
        $this->tenants->syncModules($tenant, $plan->loadMissing('modules')->moduleCodes());
    }

    public function forgetLimitsCache(Tenant $tenant): void
    {
        TenantReferenceCache::forgetForTenant((string) $tenant->id, PlanLimitService::CACHE_KEY);
    }

    /**
     * Suspends active tenants whose subscription ended before today.
     *
     * @return list<string> ids of the suspended tenants
     */
    public function expireDue(): array
    {
        $expired = [];

        Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', '<', now()->toDateString())
            ->each(function (Tenant $tenant) use (&$expired): void {
                $this->tenants->updateStatus($tenant, TenantStatus::Suspended, self::EXPIRED_REASON);
                $expired[] = (string) $tenant->id;
            });

        return $expired;
    }
}
