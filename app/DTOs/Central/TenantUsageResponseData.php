<?php

declare(strict_types=1);

namespace App\DTOs\Central;

use App\Enums\TenantStatus;
use App\Models\Central\Plan;
use App\Models\Central\TenantUsageSnapshot;
use App\Models\Tenant;
use Stancl\Tenancy\Database\Models\Domain;

/**
 * One row of the central Usage page: the tenant, its plan limits (null = unlimited) and the last
 * collected usage (`usage` null = not collected yet).
 */
final class TenantUsageResponseData
{
    private const BYTES_PER_MB = 1048576;

    /**
     * @return array<string, mixed>
     */
    public static function fromModel(Tenant $tenant): array
    {
        $plan = $tenant->relationLoaded('plan') ? $tenant->plan : null;
        $snapshot = $tenant->relationLoaded('usage') ? $tenant->usage : null;
        $domain = $tenant->relationLoaded('domains') ? $tenant->domains->first() : null;

        $status = $tenant->status instanceof TenantStatus
            ? $tenant->status->value
            : (string) ($tenant->status ?? TenantStatus::Active->value);

        $limits = array_combine(
            Plan::LIMITS,
            array_map(fn (string $key): ?int => $plan instanceof Plan ? $plan->limit($key) : null, Plan::LIMITS)
        );

        return [
            'id' => (string) $tenant->id,
            'name' => $tenant->name,
            'status' => $status,
            'primary_domain' => $domain instanceof Domain ? (string) $domain->domain : null,
            'plan' => $plan instanceof Plan ? ['id' => (int) $plan->id, 'name' => (string) $plan->name] : null,
            'subscription_ends_at' => $tenant->subscription_ends_at?->toDateString(),
            'limits' => $limits,
            'usage' => $snapshot instanceof TenantUsageSnapshot ? self::usage($snapshot) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function usage(TenantUsageSnapshot $s): array
    {
        return [
            'users' => $s->active_users,
            'users_total' => $s->total_users,
            'branches' => $s->branches,
            'warehouses' => $s->warehouses,
            'storage_mb' => (int) ceil($s->storage_bytes / self::BYTES_PER_MB),
            'sales_invoices_month' => $s->sales_invoices_month,
            'purchase_invoices_month' => $s->purchase_invoices_month,
            'last_activity_at' => $s->last_activity_at?->toIso8601String(),
            'computed_at' => $s->computed_at->toIso8601String(),
        ];
    }
}
