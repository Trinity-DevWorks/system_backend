<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Enums\TenantStatus;
use App\Models\Attachment;
use App\Models\Central\Plan;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Rbac\Models\Role;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\Central\TenantSubscriptionService;
use App\Services\ModuleEntitlementService;
use App\Services\PlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Plans against a real tenant: limits, modules following the plan, delete-in-use, and expiry.
 * Uses its own tenant so the shared test company keeps no plan (unlimited).
 */
#[Group('central')]
#[Group('tenant-db')]
class CentralPlanTenantTest extends TestCase
{
    use InteractsWithCentral;
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TENANT_ID = 'plan_limits_tenant';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant(self::TENANT_ID);
        $this->setUpCentralAdmin();
        $this->token = $this->centralToken();
    }

    protected function tearDown(): void
    {
        // Central rows are not rolled back for tenant tests: leave the tenant unlimited and active.
        $this->tenant->forceFill([
            'plan_id' => null,
            'subscription_ends_at' => null,
            'status' => TenantStatus::Active,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();
        app(TenantSubscriptionService::class)->forgetLimitsCache($this->tenant);
        app(ModuleEntitlementService::class)->grantAll($this->tenant);
        Plan::query()->delete();
        $this->tearDownCentralUsers((string) $this->centralAdmin->id);
        $this->tearDownTenant();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $modules
     */
    private function assignPlan(array $attributes, array $modules = ['sales']): Plan
    {
        $plan = Plan::query()->create([
            'name' => 'Plan '.uniqid(),
            'price' => 0,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
            ...$attributes,
        ]);
        $plan->modules()->sync([...$modules, 'core']);

        app(TenantSubscriptionService::class)->assign($this->tenant, $plan, now(), null, null);

        return $plan;
    }

    /**
     * Runs `$callback` inside the tenant and returns the PLAN_LIMIT_REACHED payload it threw, or null.
     *
     * @return array<string, mixed>|null
     */
    private function limitError(callable $callback): ?array
    {
        return $this->tenant->run(function () use ($callback): ?array {
            try {
                $callback(app(PlanLimitService::class));
            } catch (HttpResponseException $e) {
                return $e->getResponse()->getData(true);
            }

            return null;
        });
    }

    public function test_user_limit_blocks_creating_users_over_the_http_api(): void
    {
        [$activeUsers, $branchId, $roleId] = $this->tenant->run(fn (): array => [
            User::query()->where('is_active', true)->count(),
            (int) Branch::query()->value('id'),
            (int) Role::query()->value('id'),
        ]);
        $this->assignPlan(['max_users' => $activeUsers]);

        $this->asTenantRequest($this->tenantBearerToken())
            ->postJson($this->tenantUrl('users'), [
                'name' => 'One Too Many',
                'email' => 'extra@test.local',
                'password' => 'Password1!',
                'password_confirmation' => 'Password1!',
                'is_active' => true,
                'branch_assignments' => [['branch_id' => $branchId, 'role_id' => $roleId]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', PlanLimitService::ERROR_CODE)
            ->assertJsonPath('data.limit', 'users')
            ->assertJsonPath('data.max', $activeUsers);
    }

    public function test_each_limit_blocks_at_the_cap_and_null_is_unlimited(): void
    {
        [$branches, $warehouses] = $this->tenant->run(fn (): array => [
            Branch::query()->count(),
            Warehouse::query()->count(),
        ]);
        $this->assignPlan([
            'max_branches' => max(1, $branches),
            'max_warehouses' => $warehouses,
            'max_storage_mb' => 1,
        ]);

        $this->assertSame('branches', $this->limitError(fn (PlanLimitService $s) => $s->assertCanAdd('branches'))['data']['limit'] ?? null);
        $this->assertSame('warehouses', $this->limitError(fn (PlanLimitService $s) => $s->assertCanAdd('warehouses'))['data']['limit'] ?? null);
        $this->assertNull($this->limitError(fn (PlanLimitService $s) => $s->assertCanAdd('users')));

        // 1 MB plan: a 600 KB file fits, a second one does not.
        $this->assertNull($this->limitError(fn (PlanLimitService $s) => $s->assertCanStore(600 * 1024)));
        $this->tenant->run(function (): void {
            (new Attachment)->forceFill([
                'attachable_type' => 'user',
                'attachable_id' => (string) $this->tenantUser->id,
                'disk' => 'local',
                'file_path' => 'attachments/plan-test.bin',
                'file_name' => 'plan-test.bin',
                'mime_type' => 'application/octet-stream',
                'file_size' => 600 * 1024,
                'viewer_category' => 'other',
            ])->save();
        });
        $this->assertSame('storage_mb', $this->limitError(fn (PlanLimitService $s) => $s->assertCanStore(600 * 1024))['data']['limit'] ?? null);
    }

    public function test_tenant_sees_its_plan_limits_and_usage(): void
    {
        $plan = $this->assignPlan(['max_users' => 50]);

        $token = $this->tenantBearerToken();

        $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('tenant/assigned-modules'))
            ->assertOk()
            ->assertJsonPath('data.modules', ['core', 'sales']);

        // Owner (Admin role) has `subscription,view`.
        $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('tenant/subscription'))
            ->assertOk()
            ->assertJsonPath('data.plan.name', $plan->name)
            ->assertJsonPath('data.plan.billing_cycle', 'monthly')
            ->assertJsonPath('data.limits.users', 50)
            ->assertJsonPath('data.limits.branches', null)
            ->assertJsonStructure(['data' => ['subscription_starts_at', 'usage' => ['users', 'branches', 'warehouses', 'storage_mb']]]);
    }

    public function test_editing_plan_modules_resyncs_its_tenants_and_blocks_delete(): void
    {
        $plan = $this->assignPlan([]);

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl("plans/{$plan->id}"), [
                'name' => $plan->name,
                'price' => 5,
                'currency' => 'USD',
                'billing_cycle' => 'monthly',
                'is_active' => true,
                'modules' => ['accounting', 'inventory'],
            ])
            ->assertOk()
            ->assertJsonPath('data.tenants_count', 1);

        $this->assertSame(
            ['accounting', 'core', 'inventory'],
            app(ModuleEntitlementService::class)->codesForTenant(self::TENANT_ID)
        );

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl("plans/{$plan->id}"))
            ->assertStatus(409)
            ->assertJsonPath('code', 'PLAN_IN_USE');
    }

    public function test_trial_must_fall_inside_the_subscription(): void
    {
        $plan = $this->assignPlan([]);
        $url = $this->centralUrl('tenants/'.self::TENANT_ID.'/subscription');

        $this->asCentralRequest($this->token)
            ->putJson($url, [
                'plan_id' => $plan->id,
                'subscription_starts_at' => '2026-01-01',
                'subscription_ends_at' => '2026-01-31',
                'trial_ends_at' => '2026-02-15',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['trial_ends_at']);

        // No end date: any trial end on or after the start is fine; no start: compared to nothing.
        $this->asCentralRequest($this->token)
            ->putJson($url, [
                'plan_id' => $plan->id,
                'subscription_starts_at' => '2026-01-01',
                'subscription_ends_at' => null,
                'trial_ends_at' => '2026-02-15',
            ])
            ->assertOk()
            ->assertJsonPath('data.trial_ends_at', '2026-02-15');

        $this->asCentralRequest($this->token)
            ->putJson($url, [
                'plan_id' => $plan->id,
                'subscription_starts_at' => null,
                'subscription_ends_at' => null,
                'trial_ends_at' => now()->addWeek()->toDateString(),
            ])
            ->assertOk();
    }

    public function test_expired_subscriptions_suspend_the_tenant(): void
    {
        $this->assignPlan([]);
        $this->tenant->forceFill(['subscription_ends_at' => now()->subDay()->toDateString()])->save();

        $this->artisan('tenants:expire-subscriptions')->assertSuccessful();

        $this->tenant->refresh();
        $this->assertTrue($this->tenant->isSuspended());
        $this->assertSame(TenantSubscriptionService::EXPIRED_REASON, $this->tenant->suspension_reason);

        // Ends today: still active.
        $this->tenant->forceFill([
            'status' => TenantStatus::Active,
            'subscription_ends_at' => now()->toDateString(),
        ])->save();
        $this->artisan('tenants:expire-subscriptions')->assertSuccessful();
        $this->assertFalse($this->tenant->refresh()->isSuspended());
    }
}
