<?php

declare(strict_types=1);

namespace Tests\Feature\Central;

use App\Models\Central\Plan;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithCentral;
use Tests\TestCase;

/**
 * Central subscription plans: CRUD and validation (tenant-facing behaviour is in CentralPlanTenantTest).
 */
#[Group('central')]
class CentralPlanApiTest extends TestCase
{
    use InteractsWithCentral;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        app(ModuleEntitlementService::class)->syncCatalog();
        $this->setUpCentralAdmin();
        $this->token = $this->centralToken();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Basic',
            'description' => 'Small teams',
            'price' => '29.90',
            'currency' => 'usd',
            'billing_cycle' => 'monthly',
            'max_users' => 5,
            'max_branches' => 1,
            'max_warehouses' => 2,
            'max_storage_mb' => null,
            'is_active' => true,
            'modules' => ['sales', 'master_data'],
            ...$overrides,
        ];
    }

    public function test_plans_require_authentication(): void
    {
        $this->asCentralRequest()
            ->getJson($this->centralUrl('plans'))
            ->assertStatus(401);
    }

    public function test_create_forces_core_and_normalizes_fields(): void
    {
        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), $this->payload(['name' => '  Basic  ']))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Basic')
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.price', '29.90')
            ->assertJsonPath('data.modules', ['core', 'master_data', 'sales'])
            ->assertJsonPath('data.max_users', 5)
            ->assertJsonPath('data.max_storage_mb', null)
            ->assertJsonPath('data.tenants_count', 0);
    }

    public function test_list_show_update_and_delete(): void
    {
        $id = (int) $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), $this->payload())
            ->assertCreated()
            ->json('data.id');

        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl('plans?search=basic'))
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $id);

        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl('plans?section=names'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Basic');

        $this->asCentralRequest($this->token)
            ->putJson($this->centralUrl("plans/{$id}"), $this->payload([
                'name' => 'Basic',
                'billing_cycle' => 'yearly',
                'max_users' => null,
                'modules' => ['accounting'],
            ]))
            ->assertOk()
            ->assertJsonPath('data.billing_cycle', 'yearly')
            ->assertJsonPath('data.max_users', null)
            ->assertJsonPath('data.modules', ['accounting', 'core']);

        $this->asCentralRequest($this->token)
            ->getJson($this->centralUrl("plans/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.name', 'Basic');

        $this->asCentralRequest($this->token)
            ->deleteJson($this->centralUrl("plans/{$id}"))
            ->assertOk();

        $this->assertDatabaseMissing('plans', ['id' => $id]);
    }

    public function test_validation_rules(): void
    {
        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), $this->payload())
            ->assertCreated();

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), $this->payload([
                'name' => 'Broken',
                'billing_cycle' => 'weekly',
                'currency' => 'dollars',
                'max_branches' => 0,
                'price' => -1,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['billing_cycle', 'currency', 'max_branches', 'price']);

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('plans'), $this->payload(['name' => 'Unknown module', 'modules' => ['nope']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['modules']);
    }

    public function test_tenant_create_requires_an_active_plan_and_no_future_start(): void
    {
        $inactive = Plan::query()->create([
            'name' => 'Retired',
            'price' => 0,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => false,
        ]);

        $base = [
            'name' => 'No Plan Co',
            'domain' => 'noplan.localhost',
            'email' => 'owner@noplan.local',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ];

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('tenants'), $base)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id']);

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('tenants'), [...$base, 'plan_id' => $inactive->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['plan_id']);

        $active = Plan::query()->create([
            'name' => 'Current',
            'price' => 0,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);

        $this->asCentralRequest($this->token)
            ->postJson($this->centralUrl('tenants'), [
                ...$base,
                'plan_id' => $active->id,
                'subscription_starts_at' => now()->addDays(2)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subscription_starts_at']);
    }
}
