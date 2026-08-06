<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Audit;
use App\Models\User;
use App\Modules\Customer\Services\CustomerService;
use App\Services\AuditWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * What: Feature coverage for enterprise audit logging (CRUD trail, secrets, API, prune).
 * Where: tests/Feature/Audit — run locally with Postgres: `php artisan test --group=audits`.
 * Why: Proves owen-it + AuditWriter + read API behave correctly inside a real tenant DB.
 *      Skipped in CI the same way as attachment tests (needs Stancl tenant schemas).
 */
#[Group('audits')]
class AuditLogTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('audit_api');
        // PHPUnit runs in console; owen-it only audits console when audit.console=true.
        config([
            'audit.enabled' => true,
            'audit.console' => true,
        ]);
        $this->token = $this->tenantBearerToken();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_model_update_creates_audit_without_password_values(): void
    {
        $this->tenant->run(function (): void {
            $user = User::factory()->create([
                'name' => 'Before Name',
                'email' => 'audit-user@example.test',
                'password' => 'SecretPass1!',
            ]);

            $user->update([
                'name' => 'After Name',
                'password' => 'NewSecretPass1!',
            ]);

            $audit = Audit::query()
                ->where('auditable_type', 'user')
                ->where('auditable_id', (string) $user->id)
                ->where('event', 'updated')
                ->latest('id')
                ->first();

            $this->assertNotNull($audit);
            $this->assertSame('After Name', $audit->new_values['name'] ?? null);
            $this->assertArrayNotHasKey('password', $audit->old_values ?? []);
            $this->assertArrayNotHasKey('password', $audit->new_values ?? []);
        });
    }

    public function test_login_writes_security_audit_and_list_api_returns_it(): void
    {
        $email = 'owner@attach-test.local';

        $login = $this->asTenantRequest()
            ->post($this->tenantUrl('/auth/login'), [
                'email' => $email,
                'password' => 'password',
            ]);

        $login->assertOk();

        $list = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/audits?event=login'));

        $list->assertOk()
            ->assertJsonPath('success', true);

        $data = $list->json('data.data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertSame('login', $data[0]['event']);
    }

    public function test_audit_rows_are_immutable_via_eloquent(): void
    {
        $this->tenant->run(function (): void {
            /** @var AuditWriter $writer */
            $writer = app(AuditWriter::class);
            $audit = $writer->write(
                event: 'login',
                auditable: $this->tenantUser,
                user: $this->tenantUser,
                tags: 'auth,security',
            );

            $this->assertNotNull($audit);

            $this->expectException(\RuntimeException::class);
            $audit->update(['event' => 'tampered']);
        });
    }

    public function test_customer_create_is_searchable_by_auditable_filters(): void
    {
        $customerId = null;

        $this->tenant->run(function () use (&$customerId): void {
            $customer = app(CustomerService::class)->create([
                'name' => 'Audited Customer',
                'type' => 'individual',
            ]);
            $customerId = (string) $customer->id;

            $this->assertMatchesRegularExpression('/^CUST-\d{6}$/', (string) $customer->customer_code);

            $events = Audit::query()
                ->where('auditable_type', 'customer')
                ->where('auditable_id', $customerId)
                ->pluck('event')
                ->all();

            $this->assertSame(['created'], $events);
        });

        $response = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/audits?auditable_type=customer&auditable_id={$customerId}"));

        $response->assertOk();
        $rows = $response->json('data.data');
        $this->assertIsArray($rows);
        $this->assertTrue(
            collect($rows)->contains(fn (array $row): bool => ($row['event'] ?? null) === 'created')
        );
        $this->assertFalse(
            collect($rows)->contains(fn (array $row): bool => ($row['event'] ?? null) === 'updated')
        );
    }

    public function test_audits_prune_deletes_expired_rows_via_query_builder(): void
    {
        $this->tenant->run(function (): void {
            DB::table('audits')->insert([
                'user_type' => 'user',
                'user_id' => (string) $this->tenantUser->id,
                'auditable_type' => 'user',
                'auditable_id' => (string) $this->tenantUser->id,
                'event' => 'login',
                'old_values' => null,
                'new_values' => null,
                'url' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'tags' => 'auth',
                'created_at' => now()->subYears(3),
                'updated_at' => now()->subYears(3),
            ]);

            DB::table('audits')->insert([
                'user_type' => 'user',
                'user_id' => (string) $this->tenantUser->id,
                'auditable_type' => 'user',
                'auditable_id' => (string) $this->tenantUser->id,
                'event' => 'logout',
                'old_values' => null,
                'new_values' => null,
                'url' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'tags' => 'auth',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->artisan('audits:prune', ['--days' => 730])->assertSuccessful();

        $this->tenant->run(function (): void {
            $this->assertSame(0, DB::table('audits')->where('event', 'login')->count());
            $this->assertSame(1, DB::table('audits')->where('event', 'logout')->count());
        });
    }
}
