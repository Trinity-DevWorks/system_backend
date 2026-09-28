<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\DTOs\Central\TenantResponseData;
use App\Enums\TenantStatus;
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
            ->with('domains')
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
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
