<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\CentralPermission;
use App\Models\Central\CentralRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\TestCase;

/**
 * Central admin API: authentication, central RBAC, users, roles, profile.
 * Runs against Postgres (`php artisan test --group=central`).
 */
#[Group('central')]
class CentralAdminApiTest extends TestCase
{
    use InteractsWithCentral;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCentralAdmin();
    }

    public function test_admin_routes_require_authentication(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('tenants'))
            ->assertStatus(401)
            ->assertJsonPath('code', 'UNAUTHORIZED');

        $this->asCentralRequest()
            ->getJson($this->centralUrl('modules'))
            ->assertStatus(401);
    }

    public function test_login_returns_role_and_permissions(): void
    {
        $this->asCentralRequest()
            ->postJson($this->centralUrl('login'), [
                'email' => $this->centralAdmin->email,
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.role.name', 'Super Admin')
            ->assertJsonPath('data.permissions.tenants.can_add', true)
            ->assertJsonStructure(['data' => ['access_token']]);
    }

    public function test_me_returns_permission_matrix(): void
    {
        $this->asCentralRequest($this->centralToken())
            ->getJson($this->centralUrl('auth/me'))
            ->assertOk()
            ->assertJsonPath('data.role.is_system', true)
            ->assertJsonPath('data.permissions.users.can_delete', true);
    }

    public function test_user_without_permission_gets_forbidden(): void
    {
        $viewer = CentralRole::query()->create([
            'name' => 'Viewer',
            'is_active' => true,
            'is_system' => false,
        ]);
        $overview = CentralPermission::query()->where('resource_key', 'overview')->firstOrFail();
        $viewer->permissions()->attach($overview->id, ['can_view' => true]);

        $user = User::factory()->create(['central_role_id' => $viewer->id]);
        $token = $this->centralToken($user);

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('overview'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['tenants' => ['total', 'active', 'suspended'], 'users', 'modules']]);

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('tenants'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_user_crud_and_last_super_admin_protection(): void
    {
        $token = $this->centralToken();
        $superAdminId = $this->superAdminRole()->id;

        $created = $this->asCentralRequest($token)
            ->postJson($this->centralUrl('users'), [
                'name' => 'Second Admin',
                'email' => 'second-admin@platform.test',
                'password' => 'Str0ng!Passw0rd',
                'password_confirmation' => 'Str0ng!Passw0rd',
                'is_active' => true,
                'central_role_id' => $superAdminId,
            ])
            ->assertCreated()
            ->assertJsonPath('data.role.name', 'Super Admin')
            ->json('data.id');

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('users?search=second'))
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->asCentralRequest($token)
            ->deleteJson($this->centralUrl('users/'.$this->centralAdmin->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'USER_SELF_DELETE_FORBIDDEN');

        $this->asCentralRequest($token)
            ->deleteJson($this->centralUrl('users/'.$created))
            ->assertOk();

        $other = User::factory()->create(['central_role_id' => $superAdminId]);
        $otherToken = $this->centralToken($other);

        $this->asCentralRequest($otherToken)
            ->deleteJson($this->centralUrl('users/'.$this->centralAdmin->id))
            ->assertOk();

        $viewer = CentralRole::query()->create(['name' => 'Support', 'is_active' => true]);

        $this->asCentralRequest($otherToken)
            ->putJson($this->centralUrl('users/'.$other->id), [
                'name' => 'Only Admin',
                'email' => $other->email,
                'is_active' => true,
                'central_role_id' => $viewer->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'CENTRAL_LAST_SUPER_ADMIN_PROTECTED');
    }

    public function test_super_admin_role_is_locked_and_roles_can_be_managed(): void
    {
        $token = $this->centralToken();
        $superAdmin = $this->superAdminRole();

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('roles/'.$superAdmin->id), [
                'name' => 'Super Admin',
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CENTRAL_SUPER_ADMIN_EDIT_FORBIDDEN');

        $this->asCentralRequest($token)
            ->deleteJson($this->centralUrl('roles/'.$superAdmin->id))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ROLE_SYSTEM_DELETE_FORBIDDEN');

        $roleId = $this->asCentralRequest($token)
            ->postJson($this->centralUrl('roles'), ['name' => 'Support', 'is_active' => true])
            ->assertCreated()
            ->assertJsonPath('data.is_system', false)
            ->json('data.id');

        $permission = CentralPermission::query()->where('resource_key', 'tenant_modules')->firstOrFail();

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl("roles/{$roleId}/permissions"), [
                'permissions' => [[
                    'permission_id' => $permission->id,
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => false,
                    'can_delete' => true,
                    'can_import' => false,
                    'can_export' => false,
                    'can_reverse' => false,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.permissions.0.can_view', true)
            ->assertJsonPath('data.permissions.0.can_delete', false);

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('permissions'))
            ->assertOk()
            ->assertJsonFragment(['resource_key' => 'tenant_modules']);
    }

    public function test_profile_password_change_requires_current_password(): void
    {
        $token = $this->centralToken();

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('auth/me'), [
                'name' => 'Renamed Admin',
                'current_password' => 'wrong-password',
                'password' => 'N3w!Passw0rd',
                'password_confirmation' => 'N3w!Passw0rd',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENT_PASSWORD_INVALID');

        $this->asCentralRequest($token)
            ->putJson($this->centralUrl('auth/me'), ['name' => 'Renamed Admin'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed Admin');
    }

    public function test_modules_catalog_and_audits_are_readable(): void
    {
        $token = $this->centralToken();

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('modules'))
            ->assertOk();

        $this->asCentralRequest($token)
            ->getJson($this->centralUrl('audits'))
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'total', 'current_page']]);
    }
}
