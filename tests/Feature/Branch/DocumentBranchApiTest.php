<?php

declare(strict_types=1);

namespace Tests\Feature\Branch;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\BranchService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\Enums\PurchaseOrderStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Rbac\Models\Role;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Issuing branch stored on documents (docs/printing-plan.md D3): defaulting, validation and lock.
 */
#[Group('tenant-db')]
class DocumentBranchApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('document_branch_v1');
        BootstrapTenantItemTypes::dispatchSync($this->tenant);
        BootstrapTenantUnitCatalog::dispatchSync($this->tenant);
        $this->token = $this->tenantBearerToken();
        $this->catalog = $this->tenant->run(fn (): array => $this->seedCatalog());
    }

    protected function tearDown(): void
    {
        $this->tenant->run(fn () => Auth::forgetGuards());
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_branch_defaults_to_the_active_branch_for_shared_warehouses(): void
    {
        $this->createOrder($this->catalog['central_warehouse_id'])
            ->assertJsonPath('data.branch_id', $this->catalog['home_id'])
            ->assertJsonPath('data.branch.name', 'Home');

        $this->createOrder($this->catalog['central_warehouse_id'], $this->catalog['east_id'])
            ->assertJsonPath('data.branch_id', $this->catalog['east_id'])
            ->assertJsonPath('data.branch.name', 'East');
    }

    public function test_branch_warehouse_decides_the_branch(): void
    {
        $this->createOrder($this->catalog['east_warehouse_id'], $this->catalog['east_id'])
            ->assertJsonPath('data.branch_id', $this->catalog['east_id']);
    }

    public function test_moving_a_draft_to_another_branch_warehouse_moves_its_branch(): void
    {
        $id = (string) $this->createOrder($this->catalog['central_warehouse_id'], $this->catalog['east_id'])->json('data.id');

        $this->tenant->run(function () use ($id): void {
            $order = PurchaseOrder::query()->findOrFail($id);
            $order->update(['branch_id' => $this->catalog['home_id']]);
            $this->assertSame($this->catalog['home_id'], (int) $order->fresh()?->branch_id);

            $order->update(['warehouse_id' => $this->catalog['east_warehouse_id']]);
            $this->assertSame($this->catalog['east_id'], (int) $order->fresh()?->branch_id);
        });
    }

    public function test_warehouse_of_another_branch_is_rejected(): void
    {
        $this->tenant->run(function (): void {
            $this->assertAborts(422, 'DOCUMENT_BRANCH_WAREHOUSE_MISMATCH', fn () => $this->makeOrder([
                'warehouse_id' => $this->catalog['east_warehouse_id'],
                'branch_id' => $this->catalog['home_id'],
            ]));

            $order = $this->makeOrder(['warehouse_id' => $this->catalog['east_warehouse_id']]);
            $this->assertAborts(422, 'DOCUMENT_BRANCH_WAREHOUSE_MISMATCH', fn () => $order->update([
                'branch_id' => $this->catalog['home_id'],
            ]));
        });
    }

    public function test_branch_is_locked_once_the_document_leaves_draft(): void
    {
        $this->tenant->run(function (): void {
            $order = $this->makeOrder(['warehouse_id' => $this->catalog['central_warehouse_id']]);
            $order->update(['status' => PurchaseOrderStatus::Confirmed]);

            $this->assertAborts(422, 'DOCUMENT_BRANCH_LOCKED', fn () => $order->update([
                'branch_id' => $this->catalog['east_id'],
            ]));
            $this->assertSame($this->catalog['home_id'], (int) PurchaseOrder::query()->whereKey($order->id)->value('branch_id'));
        });
    }

    public function test_user_cannot_issue_documents_in_a_branch_they_are_not_assigned_to(): void
    {
        $this->tenant->run(function (): void {
            $roleId = (int) Role::query()->where('name', 'Admin')->value('id');
            $clerk = User::factory()->create(['name' => 'Home clerk', 'email' => 'home-clerk@example.com']);
            $clerk->branches()->attach($this->catalog['home_id'], ['role_id' => $roleId]);
            Auth::setUser($clerk);

            $own = $this->makeOrder(['warehouse_id' => $this->catalog['central_warehouse_id']]);
            $this->assertSame($this->catalog['home_id'], (int) $own->branch_id);

            $this->assertAborts(403, 'DOCUMENT_BRANCH_NOT_ACCESSIBLE', fn () => $this->makeOrder([
                'warehouse_id' => $this->catalog['east_warehouse_id'],
            ]));
            $this->assertAborts(403, 'DOCUMENT_BRANCH_NOT_ACCESSIBLE', fn () => $own->update([
                'branch_id' => $this->catalog['east_id'],
            ]));
        });
    }

    private function createOrder(int $warehouseId, ?int $branchHeader = null): TestResponse
    {
        $request = $this->asTenantRequest($this->token);
        if ($branchHeader !== null) {
            $request = $request->withHeaders(['X-Branch-Id' => (string) $branchHeader]);
        }

        return $request->postJson($this->tenantUrl('/stock/purchase-orders'), [
            'supplier_id' => $this->catalog['supplier_id'],
            'warehouse_id' => $warehouseId,
            'order_date' => '2026-09-01',
        ])->assertCreated();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes): PurchaseOrder
    {
        static $sequence = 0;
        $sequence++;

        return PurchaseOrder::query()->create(array_merge([
            'supplier_id' => $this->catalog['supplier_id'],
            'currency_id' => $this->catalog['usd_id'],
            'exchange_rate' => 1,
            'status' => PurchaseOrderStatus::Draft,
            'order_date' => '2026-09-01',
            'po_number' => 'PO-BR-'.$sequence,
        ], $attributes));
    }

    private function assertAborts(int $status, string $code, callable $callback): void
    {
        try {
            $callback();
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
            $this->assertSame($code, $e->getHeaders()['X-Error-Code'] ?? null);

            return;
        }

        $this->fail("Expected {$code}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create(['name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true]);
        CompanySetting::singleton()->update(['primary_currency_id' => $usd->id, 'tax_enabled' => false]);
        app(CompanySettingService::class)->forgetCache();

        $home = Branch::query()->findOrFail(app(BranchService::class)->defaultBranchId());
        $home->update(['name' => 'Home']);
        $east = Branch::query()->create(['name' => 'East', 'shortcut_name' => 'EST', 'is_active' => true, 'is_default' => false]);

        $central = $this->makeWarehouse('Central WH', 'CWH', WarehouseType::Central, null, true);
        $eastWarehouse = $this->makeWarehouse('East WH', 'EWH', WarehouseType::Branch, (int) $east->id, false);

        $supplier = Supplier::query()->create(['name' => 'Branch Supplier', 'is_active' => true]);

        return [
            'usd_id' => (int) $usd->id,
            'home_id' => (int) $home->id,
            'east_id' => (int) $east->id,
            'central_warehouse_id' => (int) $central->id,
            'east_warehouse_id' => (int) $eastWarehouse->id,
            'supplier_id' => (string) $supplier->id,
        ];
    }

    private function makeWarehouse(string $name, string $shortcut, WarehouseType $type, ?int $branchId, bool $default): Warehouse
    {
        return Warehouse::query()->create([
            'name' => $name,
            'shortcut_name' => $shortcut,
            'type' => $type,
            'branch_id' => $branchId,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => $default,
            'is_default_sales' => false,
            'is_default_production' => false,
            'is_default_purchase' => $default,
            'is_default_storage' => false,
        ]);
    }
}
