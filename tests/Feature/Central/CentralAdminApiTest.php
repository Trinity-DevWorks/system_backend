<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Tenant;
use App\Models\User;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Central (platform admin) API: only super admins may list/create tenants and change module entitlements.
 * The tenant lookup used by the Next.js proxy stays public.
 */
#[Group('tenant-db')]
class CentralAdminApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const CREATED_TENANT_ID = 'central-api-new';

    /** @var list<User> */
    private array $centralUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('central_api_test');
    }

    protected function tearDown(): void
    {
        $created = Tenant::query()->find(self::CREATED_TENANT_ID);
        if ($created !== null) {
            try {
                $created->delete();
            } catch (\Throwable) {
                Tenant::query()->whereKey(self::CREATED_TENANT_ID)->delete();
            }
        }
        foreach ($this->centralUsers as $user) {
            $user->tokens()->delete();
            $user->forceDelete();
        }
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_admin_endpoints_reject_anonymous_requests(): void
    {
        $this->getJson($this->centralUrl('/tenants'))->assertStatus(401);
        $this->postJson($this->centralUrl('/tenants'), [])->assertStatus(401);
        $this->getJson($this->centralUrl('/modules'))->assertStatus(401);
        $this->getJson($this->centralUrl('/tenants/central_api_test/modules'))->assertStatus(401);
        $this->putJson($this->centralUrl('/tenants/central_api_test/modules'), ['modules' => ['core']])->assertStatus(401);
    }

    public function test_non_super_admin_cannot_log_in_or_use_admin_endpoints(): void
    {
        $user = $this->centralUser(false);

        $this->postJson($this->centralUrl('/login'), ['email' => $user->email, 'password' => 'secret-pass-123'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');

        $token = $user->createToken('central')->plainTextToken;

        $this->withToken($token)->getJson($this->centralUrl('/tenants'))
            ->assertStatus(403)
            ->assertJsonPath('code', 'CENTRAL_FORBIDDEN');
        $this->withToken($token)->putJson($this->centralUrl('/tenants/central_api_test/modules'), ['modules' => ['core']])
            ->assertStatus(403);
    }

    public function test_super_admin_can_manage_tenants_and_modules(): void
    {
        $admin = $this->centralUser(true);

        $login = $this->postJson($this->centralUrl('/login'), ['email' => $admin->email, 'password' => 'secret-pass-123'])
            ->assertOk();
        $token = (string) $login->json('data.access_token');
        $this->assertNotSame('', $token);

        $this->withToken($token)->getJson($this->centralUrl('/tenants'))
            ->assertOk()
            ->assertJsonFragment(['id' => 'central_api_test']);

        $this->withToken($token)->getJson($this->centralUrl('/modules'))->assertOk();

        $this->withToken($token)->putJson($this->centralUrl('/tenants/central_api_test/modules'), ['modules' => ['core', 'sales']])
            ->assertOk();
        $this->assertTrue(app(ModuleEntitlementService::class)->tenantHas('central_api_test', 'sales'));

        $this->withToken($token)->postJson($this->centralUrl('/tenants'), [
            'name' => 'New Co',
            'domain' => self::CREATED_TENANT_ID.'.localhost',
            'email' => 'owner@new-co.test',
            'password' => 'Str0ng-Passw0rd!',
            'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertCreated();
        $this->assertTrue(Tenant::query()->whereKey(self::CREATED_TENANT_ID)->exists());
    }

    public function test_central_tokens_expire(): void
    {
        $admin = $this->centralUser(true);

        $this->postJson($this->centralUrl('/login'), ['email' => $admin->email, 'password' => 'secret-pass-123'])
            ->assertOk();

        $token = $admin->tokens()->latest('id')->first();
        $this->assertNotNull($token?->expires_at);
    }

    public function test_tenant_lookup_stays_public(): void
    {
        $this->getJson($this->centralUrl('/tenant/get-tenant-by-name/central_api_test'))
            ->assertOk()
            ->assertJsonPath('tenant', 'central_api_test');
    }

    private function centralUser(bool $superAdmin): User
    {
        $user = User::query()->create([
            'name' => $superAdmin ? 'Platform Admin' : 'Plain Central User',
            'email' => ($superAdmin ? 'admin' : 'plain').'-'.bin2hex(random_bytes(4)).'@central-api.test',
            'password' => 'secret-pass-123',
            'is_active' => true,
        ]);
        $user->forceFill(['is_super_admin' => $superAdmin])->save();
        $user = $user->fresh() ?? $user;
        $this->centralUsers[] = $user;

        return $user;
    }

    private function centralUrl(string $path): string
    {
        $domains = (array) config('tenancy.central_domains');
        $host = (string) ($domains[0] ?? 'app.localhost');

        return 'http://'.$host.'/api/'.ltrim($path, '/');
    }
}
