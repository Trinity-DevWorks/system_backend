<?php

declare(strict_types=1);

namespace Tests\Feature\Customer;

use App\Modules\Customer\Enums\CustomerStatus;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\WalkInCustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=WalkInCustomerTest
 */
#[Group('tenant-db')]
class WalkInCustomerTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('walk_in_customer');
        $this->token = $this->tenantBearerToken();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_tenant_bootstrap_seeds_walk_in_customer(): void
    {
        $this->tenant->run(function (): void {
            $customer = app(WalkInCustomerService::class)->current();

            $this->assertNotNull($customer);
            $this->assertSame(WalkInCustomerService::CODE, $customer->customer_code);
            $this->assertTrue($customer->is_system);
            $this->assertSame(CustomerStatus::Active, $customer->status);
        });
    }

    public function test_ensure_is_idempotent(): void
    {
        $this->tenant->run(function (): void {
            $first = app(WalkInCustomerService::class)->ensure();
            $second = app(WalkInCustomerService::class)->ensure();

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, Customer::query()->where('customer_code', WalkInCustomerService::CODE)->count());
        });
    }

    public function test_cannot_delete_walk_in_customer(): void
    {
        $id = $this->tenant->run(fn (): string => (string) app(WalkInCustomerService::class)->ensure()->id);

        $this->asTenantRequest($this->token)
            ->delete($this->tenantUrl("/customers/{$id}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_SYSTEM_DELETE_FORBIDDEN');

        $this->tenant->run(function () use ($id): void {
            $this->assertNotNull(Customer::query()->find($id));
        });
    }

    public function test_cannot_deactivate_walk_in_customer(): void
    {
        $id = $this->tenant->run(fn (): string => (string) app(WalkInCustomerService::class)->ensure()->id);

        $this->asTenantRequest($this->token)
            ->patchJson($this->tenantUrl("/customers/{$id}"), ['status' => 'suspended'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_SYSTEM_STATUS_FORBIDDEN');
    }
}
