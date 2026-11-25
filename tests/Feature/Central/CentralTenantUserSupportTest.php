<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Audit;
use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\Rbac\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Central support for a tenant's users: list, password-reset links, owner transfer.
 * Uses its own tenant; the tenant schema is reset before each test.
 */
#[Group('central')]
#[Group('tenant-db')]
class CentralTenantUserSupportTest extends TestCase
{
    use InteractsWithCentral;
    use InteractsWithTenant;
    use RefreshDatabase;

    private const TENANT_ID = 'owner_support_tenant';

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
        $this->tearDownPlatformMailbox();
        Audit::query()->where('auditable_id', self::TENANT_ID)->delete();
        $this->tearDownCentralUsers((string) $this->centralAdmin->id);
        $this->tearDownTenant();

        parent::tearDown();
    }

    private function url(string $path = ''): string
    {
        return $this->centralUrl('tenants/'.self::TENANT_ID.'/users'.$path);
    }

    /** A second active user with the Admin role on the default branch. */
    private function createStaff(string $email = 'staff@test.local', bool $active = true): User
    {
        return $this->tenant->run(function () use ($email, $active): User {
            $user = User::factory()->create(['name' => 'Staff Member', 'email' => $email, 'is_active' => $active]);
            $user->branches()->syncWithoutDetaching([
                app(BranchService::class)->defaultBranchId() => ['role_id' => (int) Role::query()->where('name', 'Admin')->value('id')],
            ]);

            return $user;
        });
    }

    public function test_lists_users_with_owner_flag_roles_and_last_login(): void
    {
        $this->createStaff();

        // A real tenant login records the `login` audit used for "last login".
        $this->asTenantRequest()
            ->postJson($this->tenantUrl('auth/login'), ['email' => $this->tenantUser->email, 'password' => 'password'])
            ->assertOk();

        $rows = collect(
            $this->asCentralRequest($this->token)->getJson($this->url())->assertOk()->json('data')
        )->keyBy('email');

        $this->assertTrue($rows[$this->tenantUser->email]['is_owner']);
        $this->assertContains('Owner', $rows[$this->tenantUser->email]['roles']);
        $this->assertNotNull($rows[$this->tenantUser->email]['last_login_at']);
        $this->assertFalse($rows['staff@test.local']['is_owner']);
        $this->assertNull($rows['staff@test.local']['last_login_at']);
    }

    public function test_reset_link_is_emailed_through_the_platform_mailbox_and_audited_without_the_token(): void
    {
        $sent = $this->usePlatformMailbox();
        $staff = $this->createStaff();

        $data = $this->asCentralRequest($this->token)
            ->postJson($this->url("/{$staff->id}/password-reset"))
            ->assertOk()
            ->assertJsonPath('data.email', 'staff@test.local')
            ->assertJsonPath('data.email_configured', true)
            ->assertJsonPath('data.email_sent', true)
            ->json('data');

        $expectedBase = config('frontend.scheme', 'http').'://'.$this->tenantDomain;
        $this->assertStringStartsWith($expectedBase, $data['url']);
        $this->assertStringContainsString('/reset-password?token=', $data['url']);

        parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $query);
        $this->tenant->run(function () use ($query): void {
            $user = User::query()->where('email', 'staff@test.local')->firstOrFail();
            $this->assertTrue(Password::broker()->tokenExists($user, (string) $query['token']));
        });

        $this->assertCount(1, $sent->messages());
        $message = $sent->messages()->first()->getOriginalMessage();
        $this->assertSame('staff@test.local', $message->getTo()[0]->getAddress());
        $this->assertSame('no-reply@platform.test', $message->getFrom()[0]->getAddress());
        $this->assertStringContainsString(htmlspecialchars($data['url']), (string) $message->getHtmlBody());

        $audit = Audit::query()->where('event', 'password_reset_link_created')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString((string) $query['token'], json_encode($audit->new_values));
    }

    public function test_without_a_platform_mailbox_only_the_link_is_returned(): void
    {
        $staff = $this->createStaff();

        $this->asCentralRequest($this->token)
            ->postJson($this->url("/{$staff->id}/password-reset"))
            ->assertOk()
            ->assertJsonPath('data.email_configured', false)
            ->assertJsonPath('data.email_sent', false)
            ->assertJsonStructure(['data' => ['url', 'expires_at']]);
    }

    public function test_tenant_forgot_password_goes_through_the_platform_mailbox_in_the_company_language(): void
    {
        $sent = $this->usePlatformMailbox();
        $this->createStaff();
        $this->tenant->run(fn () => CompanySetting::singleton()->forceFill(['preferred_language' => 'ar'])->save());

        $this->asTenantRequest()
            ->postJson($this->tenantUrl('auth/forgot-password'), ['email' => 'staff@test.local'])
            ->assertOk();

        $this->assertCount(1, $sent->messages());
        $message = $sent->messages()->first()->getOriginalMessage();
        $this->assertSame('no-reply@platform.test', $message->getFrom()[0]->getAddress());
        $this->assertStringContainsString('إعادة تعيين كلمة مرور', (string) $message->getSubject());
        $this->assertStringContainsString($this->tenantDomain, (string) $message->getTextBody());
    }

    public function test_reset_link_refuses_inactive_and_unknown_users(): void
    {
        $inactive = $this->createStaff('gone@test.local', false);

        $this->asCentralRequest($this->token)
            ->postJson($this->url("/{$inactive->id}/password-reset"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'TENANT_USER_INACTIVE');

        $this->asCentralRequest($this->token)
            ->postJson($this->url('/00000000-0000-0000-0000-000000000000/password-reset'))
            ->assertNotFound()
            ->assertJsonPath('code', 'TENANT_USER_NOT_FOUND');
    }

    public function test_make_owner_with_and_without_demoting_the_current_owner(): void
    {
        $staff = $this->createStaff();
        $ownerEmail = (string) $this->tenantUser->email;

        // Keep both as owners.
        $rows = collect(
            $this->asCentralRequest($this->token)
                ->postJson($this->url("/{$staff->id}/make-owner"), ['demote_others' => false])
                ->assertOk()
                ->json('data.users')
        )->keyBy('email');
        $this->assertTrue($rows['staff@test.local']['is_owner']);
        $this->assertTrue($rows[$ownerEmail]['is_owner']);

        // Transfer: the old owner becomes Admin.
        $this->asCentralRequest($this->token)
            ->postJson($this->url("/{$staff->id}/make-owner"), ['demote_others' => true])
            ->assertOk()
            ->assertJsonPath('data.owner.email', 'staff@test.local');

        $rows = collect($this->asCentralRequest($this->token)->getJson($this->url())->json('data'))->keyBy('email');
        $this->assertTrue($rows['staff@test.local']['is_owner']);
        $this->assertFalse($rows[$ownerEmail]['is_owner']);
        $this->assertContains('Admin', $rows[$ownerEmail]['roles']);

        $this->assertTrue(Audit::query()->where('event', 'owner_changed')->where('auditable_id', self::TENANT_ID)->exists());
    }

    public function test_inactive_users_cannot_become_owner(): void
    {
        $inactive = $this->createStaff('gone@test.local', false);

        $this->asCentralRequest($this->token)
            ->postJson($this->url("/{$inactive->id}/make-owner"), ['demote_others' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TENANT_USER_INACTIVE');

        $this->assertSame(1, $this->tenant->run(fn (): int => DB::table('branch_user')
            ->where('role_id', (int) Role::query()->where('name', 'Owner')->value('id'))
            ->count()));
    }

    public function test_requires_tenant_users_permission(): void
    {
        $this->asCentralRequest()
            ->getJson($this->url())
            ->assertStatus(401);
    }
}
