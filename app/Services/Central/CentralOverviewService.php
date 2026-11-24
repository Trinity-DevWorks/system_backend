<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\DTOs\Central\TenantResponseData;
use App\Enums\TenantStatus;
use App\Models\Central\Plan;
use App\Models\Module;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantReferenceCache;
use Illuminate\Support\Facades\Cache;

/**
 * Platform dashboard figures. Cached briefly; forgotten on tenant create/rename/status/modules changes.
 */
final class CentralOverviewService
{
    private const CACHE_KEY = 'central.overview';

    private const CACHE_TTL_SECONDS = 60;

    private const EXPIRING_DAYS = 14;

    public static function forget(): void
    {
        TenantReferenceCache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        /** @var array<string, mixed> */
        return Cache::remember(
            TenantReferenceCache::scoped(self::CACHE_KEY),
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->build()
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $byStatus = Tenant::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $active = (int) ($byStatus[TenantStatus::Active->value] ?? 0);
        $suspended = (int) ($byStatus[TenantStatus::Suspended->value] ?? 0);

        $recent = Tenant::query()
            ->with(['domains', 'plan'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $modules = Module::query()
            ->withCount('tenants')
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->map(fn (Module $m): array => [
                'code' => (string) $m->code,
                'name' => (string) $m->name,
                'is_core' => (bool) $m->is_core,
                'tenants_count' => (int) $m->getAttribute('tenants_count'),
            ])
            ->values()
            ->all();

        $plans = Plan::query()
            ->withCount('tenants')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Plan $p): array => [
                'id' => (int) $p->id,
                'name' => (string) $p->name,
                'is_active' => (bool) $p->is_active,
                'tenants_count' => (int) $p->getAttribute('tenants_count'),
            ])
            ->values()
            ->all();

        // Active tenants whose subscription ends within the next EXPIRING_DAYS days.
        $expiring = Tenant::query()
            ->with(['domains', 'plan'])
            ->where('status', TenantStatus::Active->value)
            ->whereNotNull('subscription_ends_at')
            ->whereDate('subscription_ends_at', '<=', now()->addDays(self::EXPIRING_DAYS)->toDateString())
            ->orderBy('subscription_ends_at')
            ->limit(10)
            ->get();

        return [
            'tenants' => [
                'total' => $active + $suspended,
                'active' => $active,
                'suspended' => $suspended,
                'created_last_30_days' => Tenant::query()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'users' => [
                'total' => User::query()->count(),
                'active' => User::query()->where('is_active', true)->count(),
            ],
            'recent_tenants' => TenantResponseData::collectionToArray($recent),
            'modules' => $modules,
            'plans' => $plans,
            'expiring_tenants' => TenantResponseData::collectionToArray($expiring),
            'expiring_days' => self::EXPIRING_DAYS,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
