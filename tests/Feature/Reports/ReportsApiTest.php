<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Category\Models\Category;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=ReportsApiTest
 */
#[Group('tenant-db')]
class ReportsApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('reports_v1');
        BootstrapTenantItemTypes::dispatchSync($this->tenant);
        BootstrapTenantUnitCatalog::dispatchSync($this->tenant);
        $this->token = $this->tenantBearerToken();
        $this->catalog = $this->tenant->run(fn (): array => $this->seedCatalog());
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_sales_by_period_nets_posted_invoices_and_credit_notes_and_skips_drafts(): void
    {
        $this->tenant->run(function (): void {
            $first = $this->invoice('SI-1', '2026-10-10', '2026-10-20', '100', '10', 'posted');
            $this->invoice('SI-2', '2026-10-12', '2026-10-12', '40', '0', 'posted');
            $this->invoice('SI-DRAFT', '2026-10-10', '2026-10-20', '999', '50', 'draft');
            SalesCreditNote::query()->create([
                'credit_note_number' => 'SCN-1',
                'sales_invoice_id' => $first->id,
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['currency_id'],
                'exchange_rate' => 1,
                'type' => 'return',
                'credit_date' => '2026-10-11',
                'reason' => 'Return',
                'tax_total' => 0,
                'grand_total' => 15,
                'status' => 'posted',
            ]);
        });

        $report = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/sales-by-period?from=2026-10-01&to=2026-10-31&group_by=month'))
            ->assertOk()
            ->assertJsonPath('data.rows.0.period', '2026-10-01')
            ->assertJsonPath('data.totals.document_count', 3)
            ->assertJsonPath('data.totals.gross', '140.0000')
            ->assertJsonPath('data.totals.tax', '10.0000')
            ->assertJsonPath('data.totals.net', '130.0000')
            ->assertJsonPath('data.totals.credit_note_total', '15.0000')
            ->assertJsonPath('data.totals.net_sales', '125.0000');

        $this->assertCount(1, $report->json('data.rows'));
    }

    public function test_customer_aging_buckets_open_invoices_and_skips_paid_ones(): void
    {
        $this->tenant->run(function (): void {
            $this->invoice('SI-DUE', '2026-10-01', '2026-10-23', '30', '0', 'posted', '30');
            $this->invoice('SI-CURRENT', '2026-10-20', '2026-10-31', '70', '0', 'posted', '70');
            $this->invoice('SI-PAID', '2026-10-01', '2026-10-23', '500', '0', 'posted', '0');
        });

        $report = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/customer-aging?as_of=2026-10-24&customer_id='.$this->catalog['customer_id']))
            ->assertOk()
            ->assertJsonPath('data.rows.0.code', 'CUS-1')
            ->assertJsonPath('data.rows.0.current', '70.0000')
            ->assertJsonPath('data.rows.0.1_30', '30.0000')
            ->assertJsonPath('data.rows.0.31_60', '0.0000')
            ->assertJsonPath('data.rows.0.61_90', '0.0000')
            ->assertJsonPath('data.rows.0.90_plus', '0.0000')
            ->assertJsonPath('data.totals.total', '100.0000');

        $this->assertCount(1, $report->json('data.rows'));
        $numbers = collect($report->json('data.invoices'))->pluck('number')->all();
        $this->assertEqualsCanonicalizing(['SI-DUE', 'SI-CURRENT'], $numbers);
        $this->assertNotContains('SI-PAID', $numbers);
    }

    public function test_stock_valuation_totals_match_balances_and_warehouse_filter_applies(): void
    {
        $this->tenant->run(function (): void {
            StockBalance::query()->create([
                'item_id' => $this->catalog['item_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'quantity' => 10,
                'unit_cost' => 5,
                'inventory_value' => 50,
            ]);
            StockBalance::query()->create([
                'item_id' => $this->catalog['item_id'],
                'warehouse_id' => $this->catalog['other_warehouse_id'],
                'quantity' => 4,
                'unit_cost' => 5,
                'inventory_value' => 20,
            ]);
            StockBalance::query()->create([
                'item_id' => $this->catalog['item_id'],
                'warehouse_id' => $this->catalog['empty_warehouse_id'],
                'quantity' => 0,
                'unit_cost' => 0,
                'inventory_value' => 0,
            ]);
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/stock-valuation'))
            ->assertOk()
            ->assertJsonPath('data.totals.lines', 2)
            ->assertJsonPath('data.totals.inventory_value', '70.0000')
            ->assertJsonPath('data.rows.0.unit_cost', '5.0000')
            ->assertJsonPath('data.rows.0.inventory_value', '50.0000');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/stock-valuation?warehouse_id='.$this->catalog['warehouse_id']))
            ->assertOk()
            ->assertJsonPath('data.totals.lines', 1)
            ->assertJsonPath('data.totals.inventory_value', '50.0000');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/stock-valuation?include_zero=1'))
            ->assertOk()
            ->assertJsonPath('data.totals.lines', 3)
            ->assertJsonPath('data.totals.inventory_value', '70.0000');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/stock-valuation?category_id='.$this->catalog['empty_category_id']))
            ->assertOk()
            ->assertJsonPath('data.totals.lines', 0)
            ->assertJsonPath('data.totals.inventory_value', '0.0000');
    }

    public function test_overview_omits_sales_when_the_module_is_off_and_includes_it_when_on(): void
    {
        $today = Carbon::now('UTC')->toDateString();
        $this->tenant->run(function () use ($today): void {
            $this->invoice('SI-TODAY', $today, $today, '80', '0', 'posted', '80');
        });

        app(ModuleEntitlementService::class)->syncTenantModules($this->tenant, ['core']);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/overview'))
            ->assertOk()
            ->assertJsonPath('data.currency_code', 'USD')
            ->assertJsonMissingPath('data.sales')
            ->assertJsonMissingPath('data.purchases')
            ->assertJsonMissingPath('data.inventory')
            ->assertJsonMissingPath('data.aging');

        app(ModuleEntitlementService::class)->grantAll($this->tenant);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/overview'))
            ->assertOk()
            ->assertJsonPath('data.sales.today', '80.0000')
            ->assertJsonPath('data.sales.month_to_date', '80.0000')
            ->assertJsonPath('data.sales.open_receivables', '80.0000')
            ->assertJsonPath('data.aging.receivables.total', '80.0000');
    }

    public function test_export_returns_pdf_and_xlsx_and_export_permission_is_required(): void
    {
        $pdf = $this->asTenantRequest($this->token)
            ->get($this->tenantUrl('/reports/sales-by-period/export?format=pdf&from=2026-10-01&to=2026-10-31'))
            ->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $xlsx = $this->asTenantRequest($this->token)
            ->get($this->tenantUrl('/reports/sales-by-period/export?format=xlsx&from=2026-10-01&to=2026-10-31'))
            ->assertOk();
        $binary = $xlsx->baseResponse;
        $xlsxBody = $binary instanceof BinaryFileResponse
            ? (string) file_get_contents($binary->getFile()->getPathname())
            : (string) $xlsx->getContent();
        $this->assertStringStartsWith('PK', $xlsxBody);

        $this->tenant->run(function (): void {
            $permissionId = Permission::query()->where('resource_key', 'business_reports')->value('id');
            $this->assertNotNull($permissionId);
            RolePermission::query()->where('permission_id', $permissionId)->update(['can_export' => false]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/sales-by-period/export?format=pdf'))
            ->assertForbidden();
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/sales-by-period?from=2026-10-01&to=2026-10-31'))
            ->assertOk();
    }

    private function invoice(
        string $number,
        string $date,
        string $due,
        string $grand,
        string $tax,
        string $status,
        ?string $netToPay = null,
    ): SalesInvoice {
        return SalesInvoice::query()->create([
            'invoice_number' => $number,
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['currency_id'],
            'status' => $status,
            'invoice_date' => $date,
            'due_on' => $due,
            'exchange_rate' => 1,
            'tax_total' => $tax,
            'grand_total' => $grand,
            'net_to_pay' => $netToPay ?? $grand,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $currency = Currency::query()->create([
            'name' => 'US Dollar',
            'code' => 'USD',
            'iso_code' => 'USD',
            'symbol' => '$',
            'is_active' => true,
        ]);
        CompanySetting::singleton()->update([
            'primary_currency_id' => $currency->id,
            'timezone' => 'UTC',
        ]);
        app(CompanySettingService::class)->forgetCache();

        $customer = Customer::query()->create([
            'name' => 'Report Customer',
            'customer_code' => 'CUS-1',
            'type' => 'individual',
            'status' => 'active',
        ]);
        $alpha = $this->warehouse('Alpha', 'ALP');
        $beta = $this->warehouse('Beta', 'BET');
        $gamma = $this->warehouse('Gamma', 'GAM');
        $goods = Category::query()->create([
            'code' => 'GOODS',
            'name' => 'Goods',
            'color' => '#336699',
            'is_active' => true,
        ]);
        $empty = Category::query()->create([
            'code' => 'EMPTY',
            'name' => 'Empty',
            'color' => '#999999',
            'is_active' => true,
        ]);
        $item = Item::query()->create([
            'name' => 'Stock item',
            'item_code' => 'STK-1',
            'item_type_id' => ItemType::query()->where('code', 'INVENTORY')->value('id'),
            'category_id' => $goods->id,
            'unit_group_id' => UnitGroup::query()->where('code', 'COUNT')->value('id'),
            'base_uom_id' => UnitOfMeasurement::query()->where('code', 'EA')->value('id'),
            'track_inventory' => true,
            'is_active' => true,
        ]);

        return [
            'currency_id' => (int) $currency->id,
            'customer_id' => (string) $customer->id,
            'warehouse_id' => (int) $alpha->id,
            'other_warehouse_id' => (int) $beta->id,
            'empty_warehouse_id' => (int) $gamma->id,
            'empty_category_id' => (int) $empty->id,
            'item_id' => (string) $item->id,
        ];
    }

    private function warehouse(string $name, string $shortcut): Warehouse
    {
        return Warehouse::query()->create([
            'name' => $name,
            'shortcut_name' => $shortcut,
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
        ]);
    }
}
