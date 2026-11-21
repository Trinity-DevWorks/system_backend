<?php

declare(strict_types=1);

namespace Tests\Feature\Overview;

use App\Models\User;
use App\Modules\Branch\Services\BranchService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Rbac\RbacResourceCatalog;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 */
#[Group('tenant-db')]
class TenantOverviewApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('overview_api');
        $this->token = $this->tenantBearerToken();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_guest_cannot_read_the_overview(): void
    {
        $this->getJson($this->tenantUrl('overview'))->assertUnauthorized();
    }

    public function test_owner_sees_posted_sales_in_the_primary_currency(): void
    {
        $expected = $this->tenant->run(function (): array {
            $usd = Currency::query()->create([
                'name' => 'US Dollar',
                'code' => 'USD',
                'iso_code' => 'USD',
                'symbol' => '$',
                'is_active' => true,
            ]);
            $eur = Currency::query()->create([
                'name' => 'Euro',
                'code' => 'EUR',
                'iso_code' => 'EUR',
                'symbol' => '€',
                'is_active' => true,
            ]);
            CompanySetting::singleton()->update([
                'primary_currency_id' => $usd->id,
                'timezone' => 'UTC',
            ]);
            app(CompanySettingService::class)->forgetCache();

            $today = Carbon::now('UTC')->toDateString();
            $yesterday = Carbon::now('UTC')->subDay()->toDateString();
            $previousMonth = Carbon::now('UTC')->startOfMonth()->subMonth()->toDateString();
            $customer = $this->createCustomer('Overview Customer');
            $warehouse = Warehouse::query()->create([
                'name' => 'Main',
                'shortcut_name' => 'MAIN',
                'type' => WarehouseType::Central,
                'manager_id' => $this->tenantUser->id,
                'is_active' => true,
            ]);

            $this->invoice($customer->id, $warehouse->id, $usd->id, [
                'invoice_number' => 'SI-TODAY',
                'status' => SalesInvoiceStatus::Posted,
                'invoice_date' => $today,
                'due_on' => $yesterday,
                'exchange_rate' => '1',
                'grand_total' => '100',
                'net_to_pay' => '40',
            ]);
            $this->invoice($customer->id, $warehouse->id, $eur->id, [
                'invoice_number' => 'SI-EUR',
                'status' => SalesInvoiceStatus::Posted,
                'invoice_date' => $today,
                'due_on' => Carbon::now('UTC')->addDays(10)->toDateString(),
                'exchange_rate' => '2',
                'grand_total' => '50',
                'net_to_pay' => '20',
            ]);
            $this->invoice($customer->id, $warehouse->id, $usd->id, [
                'invoice_number' => 'SI-DRAFT',
                'status' => SalesInvoiceStatus::Draft,
                'invoice_date' => $today,
                'due_on' => $today,
                'exchange_rate' => '1',
                'grand_total' => '999',
                'net_to_pay' => '999',
            ]);
            $this->invoice($customer->id, $warehouse->id, $usd->id, [
                'invoice_number' => 'SI-PREV',
                'status' => SalesInvoiceStatus::Posted,
                'invoice_date' => $previousMonth,
                'due_on' => $previousMonth,
                'exchange_rate' => '1',
                'grand_total' => '10',
                'net_to_pay' => '0',
            ]);

            return [
                'current' => Carbon::now('UTC')->format('Y-m'),
                'previous' => Carbon::now('UTC')->startOfMonth()->subMonth()->format('Y-m'),
            ];
        });

        $response = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('overview'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_name', 'Attachment Test Tenant')
            ->assertJsonPath('data.currency.code', 'USD')
            ->assertJsonPath('data.sales.month_total', '125.0000')
            ->assertJsonPath('data.sales.today_total', '125.0000')
            ->assertJsonPath('data.sales.gross_month', '125.0000')
            ->assertJsonPath('data.sales.credits_month', '0.0000')
            ->assertJsonPath('data.sales.collected_month', '0.0000')
            ->assertJsonPath('data.sales.open_total', '50.0000')
            ->assertJsonPath('data.sales.overdue_count', 1)
            ->assertJsonPath('data.sales.overdue_total', '40.0000')
            ->assertJsonPath('data.sales.draft_count', 1)
            ->assertJsonPath('data.sales.open_by_currency.0.code', 'EUR')
            ->assertJsonPath('data.sales.open_by_currency.0.amount', '20.0000')
            ->assertJsonPath('data.sales.open_by_currency.1.code', 'USD')
            ->assertJsonPath('data.sales.open_by_currency.1.amount', '40.0000')
            ->assertJsonPath('data.purchasing.month_total', '0.0000')
            ->assertJsonPath('data.accounting.revenue_month', '0.0000')
            ->assertJsonPath('data.accounting.net_month', '0.0000')
            ->assertJsonPath('data.accounting.draft_count', 0)
            ->assertJsonPath('data.inventory.alert_count', 0)
            ->assertJsonCount(6, 'data.sales.trend');

        $trend = collect($response->json('data.sales.trend'))->keyBy('period');
        $this->assertSame('125.0000', $trend[$expected['current']]['total']);
        $this->assertSame('10.0000', $trend[$expected['previous']]['total']);
        $this->assertGreaterThanOrEqual(1, $response->json('data.directory.customers'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.directory.users'));
    }

    public function test_user_without_sales_access_does_not_receive_sales_figures(): void
    {
        $token = $this->tenant->run(function (): string {
            $role = Role::query()->create([
                'name' => 'Directory Only',
                'is_active' => true,
            ]);
            $permission = Permission::query()->where('resource_key', 'users')->firstOrFail();
            RolePermission::query()->create([
                'role_id' => $role->id,
                'permission_id' => $permission->id,
                ...RbacResourceCatalog::clampFlags('users', [
                    'can_view' => true,
                    'can_add' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'can_import' => false,
                    'can_export' => false,
                ]),
            ]);
            app(PermissionService::class)->invalidateCacheForAllUsers();

            $clerk = User::factory()->create([
                'name' => 'Directory Clerk',
                'email' => 'clerk@overview-test.local',
                'is_active' => true,
            ]);
            app(BranchService::class)->assignUserToDefaultBranch($clerk, (int) $role->id);

            return $clerk->createToken('overview-clerk')->plainTextToken;
        });

        $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('overview'))
            ->assertOk()
            ->assertJsonPath('data.sales', null)
            ->assertJsonPath('data.purchasing', null)
            ->assertJsonPath('data.inventory', null)
            ->assertJsonPath('data.accounting', null)
            ->assertJsonPath('data.directory.customers', null)
            ->assertJsonPath('data.directory.users', 2);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function invoice(string $customerId, int $warehouseId, int $currencyId, array $overrides): void
    {
        SalesInvoice::query()->create([
            'customer_id' => $customerId,
            'warehouse_id' => $warehouseId,
            'currency_id' => $currencyId,
            'created_by' => $this->tenantUser->id,
            ...$overrides,
        ]);
    }
}
