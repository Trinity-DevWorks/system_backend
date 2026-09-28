<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Central tenant administration against a real tenant schema: list, detail, modules, suspend.
 */
#[Group('central')]
#[Group('tenant-db')]
class CentralTenantApiTest extends TestCase
{
    use InteractsWithCentral;
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('central_admin_tenant');
        $this->setUpCentralAdmin();
        $this->token = $this->centralToken();
    }

    protected function tearDown(): void
    {
        Audit::query()->where('user_id', (string) $this->centralAdmin->id)->delete();
        $this->tearDownCentralUsers((string) $this->centralAdmin->id);
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_list_and_show_tenant(): void
    {
        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl('tenants?search=central_admin'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', 'central_admin_tenant')
            ->assertJsonPath('data.data.0.status', 'active')
            ->assertJsonPath('data.data.0.primary_domain', $this->tenantDomain);

        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl('tenants/central_admin_tenant'))
            ->assertOk()
            ->assertJsonPath('data.name', 'Attachment Test Tenant')
            ->assertJsonPath('data.owner.email', 'owner@attach-test.local');

        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl('tenants/missing_tenant'))
            ->assertStatus(404)
            ->assertJsonPath('code', 'TENANT_NOT_FOUND');
    }

    public function test_rename_and_module_sync_are_audited(): void
    {
        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('tenants/central_admin_tenant'), ['name' => 'Renamed Tenant'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Tenant');

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('tenants/central_admin_tenant/modules'), ['modules' => ['master_data']])
            ->assertOk()
            ->assertJsonPath('data.modules', ['core', 'master_data'])
            ->assertJsonStructure(['data' => ['available']]);

        $this->assertTrue(
            Audit::query()
                ->where('event', 'modules_updated')
                ->where('auditable_id', 'central_admin_tenant')
                ->exists()
        );
    }

    public function test_suspended_tenant_is_blocked_until_reactivated(): void
    {
        $tenantToken = $this->tenantBearerToken();

        $this->asCentralRequest($this->token)
            ->patchJson($this->centralUrl('tenants/central_admin_tenant/status'), [
                'status' => 'suspended',
                'reason' => 'Unpaid invoice',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.suspension_reason', 'Unpaid invoice');

        $this->asTenantRequest($tenantToken)
            ->getJson($this->tenantUrl('auth/me'))
            ->assertStatus(401);

        $this->asTenantRequest()
            ->postJson($this->tenantUrl('auth/login'), [
                'email' => 'owner@attach-test.local',
                'password' => 'password',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_SUSPENDED');

        $this->asCentralRequest($this->token)
            ->patchJson($this->centralUrl('tenants/central_admin_tenant/status'), ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.suspended_at', null);

        $this->asTenantRequest($this->tenantBearerToken())
            ->getJson($this->tenantUrl('auth/me'))
            ->assertOk();
    }
}
