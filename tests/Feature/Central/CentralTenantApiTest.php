<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Attachment;
use App\Models\Audit;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Central tenant administration against a real tenant schema: list, detail, modules, suspend, delete.
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
            ->assertJsonPath('data.name', $this->tenant->name)
            ->assertJsonPath('data.owner.email', $this->tenantUser->email);

        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl('tenants/missing_tenant'))
            ->assertStatus(404)
            ->assertJsonPath('code', 'TENANT_NOT_FOUND');
    }

    public function test_rename_and_plan_change_are_audited(): void
    {
        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('tenants/central_admin_tenant'), ['name' => 'Renamed Tenant'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Tenant');

        $planId = (int) $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), [
                'name' => 'Master data only',
                'price' => 10,
                'currency' => 'usd',
                'billing_cycle' => 'monthly',
                'is_active' => true,
                'modules' => ['master_data'],
            ])
            ->assertCreated()
            ->json('data.id');

        // Modules follow the plan; the old per-tenant module editing is gone.
        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('tenants/central_admin_tenant/subscription'), [
                'plan_id' => $planId,
                'subscription_starts_at' => '2026-01-01',
                'subscription_ends_at' => '2099-01-31',
            ])
            ->assertOk()
            ->assertJsonPath('data.plan.name', 'Master data only')
            ->assertJsonPath('data.subscription_ends_at', '2099-01-31')
            ->assertJsonPath('data.modules', ['core', 'master_data']);

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl('tenants/central_admin_tenant/modules'), ['modules' => ['core']])
            ->assertStatus(405);

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
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_SUSPENDED');

        $this->asTenantRequest()
            ->postJson($this->tenantUrl('auth/login'), [
                'email' => $this->tenantUser->email,
                'password' => 'password',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'TENANT_SUSPENDED');

        $this->asCentralRequest($this->token)
            ->patchJson($this->centralUrl('tenants/central_admin_tenant/status'), ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.suspended_at', null);

        // Suspending revoked every tenant token, so the old one stays dead after reactivation.
        $this->app['auth']->forgetGuards();
        $this->asTenantRequest($tenantToken)
            ->getJson($this->tenantUrl('auth/me'))
            ->assertStatus(401);

        $this->asTenantRequest($this->tenantBearerToken())
            ->getJson($this->tenantUrl('auth/me'))
            ->assertOk();
    }

    public function test_delete_requires_suspension_and_typed_confirmation(): void
    {
        $filePath = 'attachments/central-delete-test/'.Str::uuid()->toString().'.txt';
        $this->tenant->run(function () use ($filePath): void {
            Storage::disk('local')->put($filePath, 'tenant file');
            (new Attachment)->forceFill([
                'attachable_type' => 'user',
                'attachable_id' => (string) $this->tenantUser->id,
                'disk' => 'local',
                'file_path' => $filePath,
                'file_name' => 'note.txt',
                'mime_type' => 'text/plain',
                'file_size' => 11,
                'viewer_category' => 'text',
            ])->save();
            $this->assertTrue(Storage::disk('local')->exists($filePath));
        });
        $absoluteFilePath = $this->tenant->run(fn (): string => Storage::disk('local')->path($filePath));

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl('tenants/central_admin_tenant'), ['confirmation' => 'central_admin_tenant'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'TENANT_DELETE_REQUIRES_SUSPENSION');

        $this->asCentralRequest($this->token)
            ->patchJson($this->centralUrl('tenants/central_admin_tenant/status'), ['status' => 'suspended'])
            ->assertOk();

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl('tenants/central_admin_tenant'), ['confirmation' => 'wrong'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmation', 'errors');

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl('tenants/central_admin_tenant'), ['confirmation' => 'central_admin_tenant'])
            ->assertOk();

        $this->assertNull(Tenant::query()->find('central_admin_tenant'));
        $this->assertSame(0, DB::table('domains')->where('tenant_id', 'central_admin_tenant')->count());
        $this->assertSame(0, DB::table('tenant_modules')->where('tenant_id', 'central_admin_tenant')->count());
        $this->assertSame(
            [],
            DB::select('select 1 from information_schema.schemata where schema_name = ?', ['central_admin_tenant'])
        );
        $this->assertFileDoesNotExist($absoluteFilePath);

        $audit = Audit::query()
            ->where('user_id', (string) $this->centralAdmin->id)
            ->where('event', 'deleted')
            ->where('auditable_type', 'tenant')
            ->where('auditable_id', 'central_admin_tenant')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame([$this->tenantDomain], $audit->old_values['domains'] ?? null);

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl('tenants/central_admin_tenant'), ['confirmation' => 'central_admin_tenant'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'TENANT_NOT_FOUND');
    }
}
