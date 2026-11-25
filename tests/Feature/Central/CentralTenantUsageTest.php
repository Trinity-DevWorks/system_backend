<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\Plan;
use App\Models\Central\TenantUsageSnapshot;
use App\Models\User;
use App\Services\Central\TenantSubscriptionService;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Central Usage page: usage collected from a tenant schema into tenant_usage_snapshots.
 */
#[Group('central')]
#[Group('tenant-db')]
class CentralTenantUsageTest extends TestCase
{
    use InteractsWithCentral;
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TENANT_ID = 'usage_tenant';

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
        TenantUsageSnapshot::query()->delete();
        $this->tenant->forceFill(['plan_id' => null])->save();
        app(TenantSubscriptionService::class)->forgetLimitsCache($this->tenant);
        app(ModuleEntitlementService::class)->grantAll($this->tenant);
        Plan::query()->delete();
        $this->tearDownCentralUsers((string) $this->centralAdmin->id);
        $this->tearDownTenant();

        parent::tearDown();
    }

    private function row(): array
    {
        $rows = collect(
            $this->asCentralRequest($this->token)
                ->getJson($this->centralUrl('tenants/usage?search='.self::TENANT_ID))
                ->assertOk()
                ->json('data.data')
        )->keyBy('id');

        return $rows[self::TENANT_ID];
    }

    public function test_usage_is_empty_until_collected_then_counts_the_tenant(): void
    {
        $this->assertNull($this->row()['usage']);

        $expected = $this->tenant->run(fn (): array => [
            'active' => User::query()->where('is_active', true)->count(),
            'total' => User::query()->count(),
        ]);

        // A real login gives the tenant a "last activity".
        $this->asTenantRequest()
            ->postJson($this->tenantUrl('auth/login'), ['email' => $this->tenantUser->email, 'password' => 'password'])
            ->assertOk();

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('tenants/'.self::TENANT_ID.'/usage/refresh'))
            ->assertOk()
            ->assertJsonPath('data.usage.users', $expected['active'])
            ->assertJsonPath('data.usage.users_total', $expected['total'])
            ->assertJsonPath('data.usage.sales_invoices_month', 0);

        $usage = $this->row()['usage'];
        $this->assertNotNull($usage['last_activity_at']);
        $this->assertNotNull($usage['computed_at']);
        $this->assertGreaterThanOrEqual(1, $usage['branches']);
    }

    public function test_rows_carry_the_plan_limits(): void
    {
        $plan = Plan::query()->create([
            'name' => 'Small',
            'price' => 10,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'max_users' => 5,
            'max_storage_mb' => 100,
            'is_active' => true,
        ]);
        $plan->modules()->sync(['core']);
        app(TenantSubscriptionService::class)->assign($this->tenant, $plan, now(), null, null);

        $row = $this->row();
        $this->assertSame('Small', $row['plan']['name']);
        $this->assertSame(5, $row['limits']['users']);
        $this->assertNull($row['limits']['branches']);
        $this->assertSame(100, $row['limits']['storage_mb']);
    }

    public function test_refresh_all_and_the_hourly_command(): void
    {
        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('tenants/usage/refresh'))
            ->assertOk()
            ->assertJsonPath('data.failed', []);
        $this->assertTrue(TenantUsageSnapshot::query()->whereKey(self::TENANT_ID)->exists());

        TenantUsageSnapshot::query()->delete();
        $this->artisan('tenants:refresh-usage')->assertSuccessful();
        $this->assertTrue(TenantUsageSnapshot::query()->whereKey(self::TENANT_ID)->exists());
    }

    public function test_requires_authentication(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('tenants/usage'))
            ->assertStatus(401);
    }
}
