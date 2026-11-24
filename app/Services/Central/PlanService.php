<?php

declare(strict_types=1);

namespace App\Services\Central;

use App\Enums\BillingCycle;
use App\Models\Central\Plan;
use App\Models\Module;
use App\Models\Tenant;
use App\Support\ListPagination;
use App\Support\TenantReferenceCache;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Subscription plans: CRUD, plus pushing a plan's modules to every tenant on it.
 */
final class PlanService
{
    private const CACHE_LIST = 'central.plans.list';

    public function __construct(
        private readonly TenantSubscriptionService $subscriptions,
    ) {}

    public static function forgetListCache(): void
    {
        TenantReferenceCache::forget(self::CACHE_LIST);
    }

    /**
     * Plans for selects (`?section=names`), ordered like the table.
     *
     * @return Collection<int, Plan>
     */
    public function list(): Collection
    {
        return TenantReferenceCache::rememberModels(
            self::CACHE_LIST,
            Plan::class,
            fn (): Collection => Plan::query()->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    public function paginateForTable(?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Plan::query()
            ->with('modules')
            ->withCount('tenants')
            ->orderBy('sort_order')
            ->orderBy('name');
        ListPagination::applySearch($query, $search, ['name', 'description']);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data  validated StorePlanRequest
     */
    public function create(array $data): Plan
    {
        $plan = $this->transaction(function () use ($data): Plan {
            $plan = Plan::query()->create($this->attributes($data));
            $plan->modules()->sync($this->normalizeModules($data['modules'] ?? []));

            return $plan;
        });

        self::forgetListCache();
        CentralOverviewService::forget();

        return $this->fresh($plan);
    }

    /**
     * Changing the modules re-syncs every tenant on the plan; changing limits clears their cached limits.
     *
     * @param  array<string, mixed>  $data  validated UpdatePlanRequest
     */
    public function update(Plan $plan, array $data): Plan
    {
        $before = $plan->load('modules')->moduleCodes();
        $after = $this->normalizeModules($data['modules'] ?? $before);

        $this->transaction(function () use ($plan, $data, $after): void {
            $plan->update($this->attributes($data));
            $plan->modules()->sync($after);
        });

        $plan->load('modules');
        Tenant::query()->where('plan_id', $plan->id)->each(function (Tenant $tenant) use ($plan, $before, $after): void {
            if ($before !== $after) {
                $this->subscriptions->syncModulesFromPlan($tenant, $plan);
            }
            $this->subscriptions->forgetLimitsCache($tenant);
        });

        self::forgetListCache();
        CentralOverviewService::forget();

        return $this->fresh($plan);
    }

    public function delete(Plan $plan): void
    {
        if ($plan->tenants()->exists()) {
            abort(409, 'Move the tenants on this plan to another plan before deleting it.', ['X-Error-Code' => 'PLAN_IN_USE']);
        }

        $plan->delete();
        self::forgetListCache();
        CentralOverviewService::forget();
    }

    /**
     * Seeds the "Unlimited" plan (every module, no limits) when no plan exists, and puts
     * every tenant without a plan on it. Safe to run repeatedly.
     */
    public function ensureDefaultPlan(): Plan
    {
        $plan = Plan::query()->where('name', Plan::DEFAULT_PLAN_NAME)->first()
            ?? Plan::query()->orderBy('sort_order')->orderBy('id')->first();

        if ($plan === null) {
            $plan = Plan::query()->create([
                'name' => Plan::DEFAULT_PLAN_NAME,
                'description' => 'Every module, no limits.',
                'price' => 0,
                'currency' => 'USD',
                'billing_cycle' => BillingCycle::Monthly,
                'is_active' => true,
                'sort_order' => 0,
            ]);
            $plan->modules()->sync(Module::query()->pluck('code')->all());
            self::forgetListCache();
        }

        Tenant::query()->whereNull('plan_id')->each(
            fn (Tenant $tenant) => $this->subscriptions->assign($tenant, $plan, now()->startOfDay(), null, null)
        );

        return $plan;
    }

    /**
     * Known codes only, `core` always included (same rule as tenant module sync).
     *
     * @param  array<int, mixed>  $codes
     * @return list<string>
     */
    public function normalizeModules(array $codes): array
    {
        $requested = array_values(array_unique(array_map('strval', $codes)));
        $known = Module::query()->pluck('code')->map(fn ($c): string => (string) $c)->all();

        $unknown = array_values(array_diff($requested, $known));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'modules' => ['Unknown module code(s): '.implode(', ', $unknown)],
            ]);
        }

        if (! in_array('core', $requested, true)) {
            $requested[] = 'core';
        }
        sort($requested);

        return $requested;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'currency' => strtoupper((string) $data['currency']),
            'billing_cycle' => $data['billing_cycle'],
            'max_users' => $data['max_users'] ?? null,
            'max_branches' => $data['max_branches'] ?? null,
            'max_warehouses' => $data['max_warehouses'] ?? null,
            'max_storage_mb' => $data['max_storage_mb'] ?? null,
            'is_active' => $data['is_active'],
            'sort_order' => $data['sort_order'] ?? 0,
        ];
    }

    private function fresh(Plan $plan): Plan
    {
        return $plan->refresh()->load('modules')->loadCount('tenants');
    }

    private function transaction(callable $callback): mixed
    {
        return DB::connection(config('tenancy.database.central_connection'))->transaction($callback);
    }
}
