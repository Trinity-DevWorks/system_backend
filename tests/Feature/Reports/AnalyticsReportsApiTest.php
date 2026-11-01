<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Reports\Services\SalesProfitService;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\PermissionService;
use App\Support\Decimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Sales analysis / profitability, cash flow, general ledger, lot expiry, dead stock, item movement.
 */
#[Group('tenant-db')]
class AnalyticsReportsApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('analytics_reports_v1');
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

    public function test_sales_analysis_and_profitability_include_delivery_notes_and_returns(): void
    {
        $this->seedSales();

        $byItem = $this->report('sales-analysis', ['group_by' => 'item'])->json('data');
        $row = collect($byItem['rows'])->firstWhere('code', 'STK-R');
        // 2 direct + 3 via delivery note - 1 returned, all at 20; cost 5 each.
        $this->assertSame('4.000000', $row['qty']);
        $this->assertSame('100.0000', $row['sales']);
        $this->assertSame('20.0000', $row['returns']);
        $this->assertSame('80.0000', $row['net_sales']);
        $this->assertSame('20.0000', $row['cost']);
        $this->assertSame('60.0000', $row['gross_profit']);
        $this->assertSame('75.0', $row['margin']);
        $this->assertSame('60.0000', $byItem['totals']['gross_profit']);

        // Server table: search, sort and pagination; totals cover all matching rows.
        $paged = $this->report('sales-analysis', ['group_by' => 'item', 'per_page' => 1, 'page' => 1, 'sort' => 'name', 'dir' => 'asc'])->json('data');
        $this->assertCount(1, $paged['rows']);
        $this->assertSame(1, $paged['pagination']['page']);
        $this->assertSame(count($byItem['rows']), $paged['pagination']['total']);
        $this->assertSame($byItem['totals']['gross_profit'], $paged['totals']['gross_profit']);
        $names = collect($byItem['rows'])->pluck('name')->sort(fn ($a, $b) => strnatcasecmp($a, $b))->values();
        $this->assertSame($names->first(), $paged['rows'][0]['name']);

        $searched = $this->report('sales-analysis', ['group_by' => 'item', 'search' => 'stk-r', 'per_page' => 20])->json('data');
        $this->assertSame(['STK-R'], collect($searched['rows'])->pluck('code')->all());
        $this->assertSame('60.0000', $searched['totals']['gross_profit']);

        $none = $this->report('sales-analysis', ['group_by' => 'item', 'search' => 'no-such-item', 'per_page' => 20])->json('data');
        $this->assertSame([], $none['rows']);
        $this->assertSame(0, $none['pagination']['total']);

        $byCustomer = $this->report('sales-analysis', ['group_by' => 'customer'])->json('data');
        $this->assertSame('Report Customer', $byCustomer['rows'][0]['name']);
        $this->assertSame([['key' => 'name', 'type' => 'text']], array_slice($byCustomer['columns'], 0, 1));

        $docs = $this->report('sales-profitability')->json('data');
        $this->assertCount(3, $docs['rows']);
        $this->assertSame('60.0000', $docs['totals']['gross_profit']);
    }

    /**
     * The SQL grouping must give the same numbers as adding up the line-by-line contributions
     * (the calculation the profitability report uses), for every grouping.
     */
    public function test_sql_sales_analysis_matches_line_by_line_calculation(): void
    {
        $this->seedSales();

        // Second customer, odd prices (rounding), two lines of the same item (cost shares).
        $other = (string) $this->tenant->run(fn (): string => (string) Customer::query()->create(['name' => 'Other Customer', 'type' => 'individual', 'status' => 'active'])->id);
        $split = $this->createAndPost('/sales-invoices', [
            'customer_id' => $other,
            'warehouse_id' => $this->catalog['warehouse_id'],
            'lines' => [
                ['item_id' => $this->catalog['stock_item_id'], 'quantity' => 1, 'unit_price' => 6.67],
                ['item_id' => $this->catalog['stock_item_id'], 'quantity' => 2, 'unit_price' => 3.33],
            ],
        ], 'sales-invoices');
        // Return without restock: reduces sales, puts no cost back.
        $splitLine = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$split}"))->json('data.lines.0.id');
        $this->createAndPost('/sales-credit-notes', [
            'sales_invoice_id' => $split,
            'type' => 'return',
            'credit_date' => now()->toDateString(),
            'reason' => 'Damaged',
            'lines' => [['sales_invoice_line_id' => $splitLine, 'quantity' => 1, 'warehouse_id' => $this->catalog['warehouse_id'], 'restock' => false]],
        ], 'sales-credit-notes');
        // Posted then reversed in the same period: counts +1 and -1.
        $reversed = $this->createAndPost('/sales-invoices', [
            'customer_id' => $other,
            'warehouse_id' => $this->catalog['warehouse_id'],
            'lines' => [['item_id' => $this->catalog['stock_item_id'], 'quantity' => 1, 'unit_price' => 9.99]],
        ], 'sales-invoices');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$reversed}/reverse"))->assertOk();

        foreach (['item', 'customer', 'salesman', 'branch', 'category'] as $groupBy) {
            $report = $this->report('sales-analysis', ['group_by' => $groupBy])->json('data');
            $expected = $this->tenant->run(fn (): array => $this->expectedAnalysis($groupBy));
            $this->assertNotEmpty($expected['rows'], "no data for group_by={$groupBy}");
            if ($groupBy === 'customer') {
                $this->assertCount(2, $expected['rows']);
            }

            $actual = collect($report['rows'])
                ->map(fn (array $r): array => array_intersect_key($r, array_flip(['code', 'name', 'qty', 'sales', 'returns', 'net_sales', 'cost', 'gross_profit', 'margin'])))
                ->sortBy(fn (array $r): string => ($r['code'] ?? '').'|'.$r['name'])->values()->all();
            $this->assertSame($expected['rows'], $actual, "rows differ for group_by={$groupBy}");
            $this->assertSame($expected['totals']['gross_profit'], $report['totals']['gross_profit'], "totals differ for group_by={$groupBy}");
            $this->assertSame($expected['totals']['net_sales'], $report['totals']['net_sales'], "totals differ for group_by={$groupBy}");
        }

        // Invoice profitability (per document and event) against the same line-by-line calculation.
        $docs = $this->report('sales-profitability')->json('data');
        $expectedDocs = $this->tenant->run(fn (): array => $this->expectedProfitability());
        $key = fn (array $r): string => $r['document'].'|'.$r['number'].'|'.$r['net_sales'];
        $actualDocs = collect($docs['rows'])
            ->map(fn (array $r): array => array_intersect_key($r, array_flip(['number', 'date', 'customer', 'document', 'sales', 'returns', 'net_sales', 'cost', 'gross_profit', 'margin'])))
            ->sortBy($key)->values()->all();
        $this->assertCount(7, $expectedDocs['rows']); // 4 invoices, 2 credit notes, 1 reversal row
        $this->assertSame(collect($expectedDocs['rows'])->sortBy($key)->values()->all(), $actualDocs);
        $this->assertSame($expectedDocs['totals']['gross_profit'], $docs['totals']['gross_profit']);

        // Default order: date, then number.
        $this->assertSame(collect($docs['rows'])->sortBy(fn (array $r): string => $r['date'].'|'.$r['number'])->pluck('number')->values()->all(), collect($docs['rows'])->pluck('number')->all());

        // Paging gives the same rows as the full list, page after page.
        $this->assertPagesMatchFull('sales-profitability', [], 2);
        $this->assertPagesMatchFull('sales-analysis', ['group_by' => 'customer'], 1);
    }

    public function test_general_ledger_dead_stock_and_lot_expiry_pages_match_full_report(): void
    {
        $this->seedSales();

        // Ledger pages (SQL) must equal the full ledger (opening, lines, running balance, closing).
        $this->assertPagesMatchFull('general-ledger', [], 3);
        // Line rows carry what the screen needs to open the journal entry and its document.
        $line = collect($this->report('general-ledger', ['per_page' => 50])->json('data.rows'))->first(fn (array $r): bool => ! isset($r['row_kind']));
        $this->assertNotNull($line['entry_id']);
        $this->assertSame('sales_invoice', collect($this->report('general-ledger', ['per_page' => 50])->json('data.rows'))
            ->firstWhere('source_type', 'sales_invoice')['source_type'] ?? null);
        $searched = $this->report('general-ledger', ['search' => 'zzz-no-account', 'per_page' => 20])->json('data');
        $this->assertSame([], $searched['rows']);

        $this->assertPagesMatchFull('dead-stock', ['days' => 0], 1);
        $this->assertPagesMatchFull('lot-expiry', ['days' => 30], 1);

        $sorted = $this->report('dead-stock', ['days' => 0, 'per_page' => 20, 'sort' => 'code', 'dir' => 'desc'])->json('data');
        $codes = collect($sorted['rows'])->pluck('code')->all();
        $this->assertSame(collect($codes)->sortDesc()->values()->all(), $codes);
        $this->assertSame('code', $sorted['pagination']['sort']);
    }

    /**
     * Walk every page of a report and compare it with the full (unpaged) report.
     *
     * @param  array<string, mixed>  $query
     */
    private function assertPagesMatchFull(string $name, array $query, int $perPage): void
    {
        $full = $this->report($name, $query)->json('data');
        $this->assertNotEmpty($full['rows'], "{$name}: no rows to page");

        $collected = [];
        $page = 1;
        do {
            $chunk = $this->report($name, [...$query, 'page' => $page, 'per_page' => $perPage])->json('data');
            $collected = [...$collected, ...$chunk['rows']];
            $this->assertSame(count($full['rows']), $chunk['pagination']['total'], "{$name}: total");
            $this->assertEquals($full['totals'], $chunk['totals'], "{$name}: totals on page {$page}");
            $page++;
        } while (count($collected) < count($full['rows']) && $page <= 100);

        $this->assertSame($full['rows'], $collected, "{$name}: pages differ from the full report");
    }

    /**
     * Old per-document grouping of SalesProfitService::contributions() (invoice profitability reference).
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function expectedProfitability(): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $filters = app(ReportBranchScope::class)->apply(null)['filters'];

        $docs = [];
        foreach (app(SalesProfitService::class)->contributions($from, $to, $filters) as $c) {
            $k = $c['kind'].':'.$c['document_id'].':'.$c['sign'];
            $d = $docs[$k] ?? ['number' => $c['number'], 'date' => $c['event_date'], 'customer' => $c['customer_name'], 'kind' => $c['kind'], 'sales' => '0', 'returns' => '0', 'cost' => '0'];
            if ($c['kind'] === 'credit_note') {
                $d['returns'] = Decimal::add($d['returns'], Decimal::neg($c['net']));
            } else {
                $d['sales'] = Decimal::add($d['sales'], $c['net']);
            }
            $d['cost'] = Decimal::add($d['cost'], $c['cost']);
            $docs[$k] = $d;
        }

        $row = function (array $base, string $sales, string $returns, string $cost): array {
            $net = Decimal::sub($sales, $returns);
            $profit = Decimal::sub($net, $cost);

            return [...$base,
                'sales' => Decimal::round($sales, 4), 'returns' => Decimal::round($returns, 4), 'net_sales' => Decimal::round($net, 4),
                'cost' => Decimal::round($cost, 4), 'gross_profit' => Decimal::round($profit, 4),
                'margin' => Decimal::isZero($net) ? null : Decimal::round(Decimal::mul(Decimal::div($profit, $net), '100'), 1),
            ];
        };
        $rows = array_map(fn (array $d): array => $row(['number' => $d['number'], 'date' => $d['date'], 'customer' => $d['customer'], 'document' => $d['kind']], $d['sales'], $d['returns'], $d['cost']), array_values($docs));
        $sum = fn (string $k): string => array_reduce($rows, fn (string $carry, array $r): string => Decimal::add($carry, $r[$k]), '0');

        return ['rows' => $rows, 'totals' => $row([], $sum('sales'), $sum('returns'), $sum('cost'))];
    }

    /**
     * Old in-memory grouping of SalesProfitService::contributions(), kept here as the reference.
     *
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function expectedAnalysis(string $groupBy): array
    {
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();
        $filters = app(ReportBranchScope::class)->apply(null)['filters'];

        $groups = [];
        foreach (app(SalesProfitService::class)->contributions($from, $to, $filters) as $c) {
            [$key, $code, $name] = match ($groupBy) {
                'customer' => [$c['customer_id'], null, $c['customer_name']],
                'salesman' => [$c['salesman_id'] ?? '-', null, $c['salesman_name'] ?? '—'],
                'branch' => [(string) $c['branch_id'], null, $c['branch_name']],
                'category' => [(string) ($c['category_id'] ?? '-'), null, $c['category_name'] ?? '—'],
                default => [$c['item_id'], $c['item_code'], $c['item_name']],
            };
            $g = $groups[$key] ?? ['code' => $code, 'name' => $name, 'qty' => '0', 'sales' => '0', 'returns' => '0', 'cost' => '0'];
            $g['qty'] = Decimal::add($g['qty'], $c['qty']);
            if ($c['kind'] === 'credit_note') {
                $g['returns'] = Decimal::add($g['returns'], Decimal::neg($c['net']));
            } else {
                $g['sales'] = Decimal::add($g['sales'], $c['net']);
            }
            $g['cost'] = Decimal::add($g['cost'], $c['cost']);
            $groups[$key] = $g;
        }

        $row = function (array $base, string $sales, string $returns, string $cost): array {
            $net = Decimal::sub($sales, $returns);
            $profit = Decimal::sub($net, $cost);

            return [...$base,
                'sales' => Decimal::round($sales, 4), 'returns' => Decimal::round($returns, 4), 'net_sales' => Decimal::round($net, 4),
                'cost' => Decimal::round($cost, 4), 'gross_profit' => Decimal::round($profit, 4),
                'margin' => Decimal::isZero($net) ? null : Decimal::round(Decimal::mul(Decimal::div($profit, $net), '100'), 1),
            ];
        };
        $rows = array_map(fn (array $g): array => $row(['code' => $g['code'], 'name' => $g['name'], 'qty' => Decimal::round($g['qty'], 6)], $g['sales'], $g['returns'], $g['cost']), array_values($groups));
        $sum = fn (string $k): string => array_reduce($rows, fn (string $carry, array $r): string => Decimal::add($carry, $r[$k]), '0');
        $totals = $row([], $sum('sales'), $sum('returns'), $sum('cost'));
        usort($rows, fn (array $a, array $b): int => strcmp(($a['code'] ?? '').'|'.$a['name'], ($b['code'] ?? '').'|'.$b['name']));

        return ['rows' => $rows, 'totals' => $totals];
    }

    public function test_cash_flow_and_general_ledger(): void
    {
        $invoice = $this->seedSales();
        $this->createAndPost('/customer-receipts', [
            'customer_id' => $this->catalog['customer_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['cash_method_id'],
            'payment_date' => now()->toDateString(),
            'amount' => 20,
            'allocations' => [['sales_invoice_id' => $invoice, 'amount' => 20]],
        ], 'customer-receipts');

        $flow = $this->report('cash-flow')->json('data');
        $rows = collect($flow['rows'])->keyBy('category');
        $this->assertSame('0.0000', $rows['opening_cash']['net']);
        // The direct invoice (40) was credited 20, so 20 was open and received.
        $this->assertSame('20.0000', $rows['customers']['cash_in']);
        $this->assertSame('20.0000', $rows['closing_cash']['net']);

        $gl = $this->report('general-ledger')->json('data');
        $this->assertNotEmpty($gl['rows']);
        $this->assertSame($gl['totals']['debit'], $gl['totals']['credit']);
        $this->assertContains('opening', collect($gl['rows'])->pluck('row_kind')->filter()->unique()->values()->all());
    }

    public function test_lot_expiry_dead_stock_and_item_movement(): void
    {
        $this->seedSales();

        $expiry = $this->report('lot-expiry', ['days' => 30])->json('data');
        $this->assertSame('LOT-1', $expiry['rows'][0]['lot']);
        $this->assertSame(10, $expiry['rows'][0]['days_left']);
        $this->assertCount(0, $this->report('lot-expiry', ['days' => 5])->json('data.rows'));

        // Nothing is idle for 90 days yet; with 0 days every stocked item without recent sales shows.
        $this->assertCount(0, $this->report('dead-stock', ['days' => 90])->json('data.rows'));
        $dead = collect($this->report('dead-stock', ['days' => 0])->json('data.rows'))->pluck('code')->all();
        $this->assertContains('LOT-ITEM', $dead);

        $movement = $this->report('item-movement', ['item_id' => $this->catalog['stock_item_id']])->json('data');
        $this->assertSame('opening', $movement['rows'][0]['row_kind']);
        $this->assertSame('closing', end($movement['rows'])['row_kind']);
        $this->assertSame('6.000000', end($movement['rows'])['balance']); // 10 - 2 - 3 + 1
        $this->assertNotNull($movement['rows'][1]['type']);
        $this->assertArrayHasKey('uom', $movement['meta']);
        $this->assertNotContains('lot', collect($movement['columns'])->pluck('key')->all()); // not lot-tracked

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/reports/item-movement'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'REPORT_ITEM_REQUIRED');
    }

    public function test_exports_and_permission(): void
    {
        $this->seedSales();
        foreach (['pdf' => '%PDF', 'xlsx' => 'PK'] as $format => $magic) {
            $response = $this->asTenantRequest($this->token)->get($this->tenantUrl('/reports/sales-analysis/export?format='.$format));
            $response->assertOk();
            $this->assertStringStartsWith($magic, $this->body($response));
        }

        $this->tenant->run(function (): void {
            $permission = Permission::query()->where('resource_key', 'business_reports')->firstOrFail();
            RolePermission::query()->where('permission_id', $permission->id)->update(['can_export' => false]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });
        $this->asTenantRequest($this->token)->get($this->tenantUrl('/reports/sales-analysis/export?format=pdf'))->assertStatus(403);
    }

    /**
     * Direct invoice (2), delivery note invoiced (3), return of 1 from the direct invoice.
     * Returns the direct invoice id.
     */
    private function seedSales(): string
    {
        $direct = $this->createAndPost('/sales-invoices', [
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'lines' => [['item_id' => $this->catalog['stock_item_id'], 'quantity' => 2, 'unit_price' => 20]],
        ], 'sales-invoices');

        $order = (string) $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/sales-orders'), [
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'lines' => [['item_id' => $this->catalog['stock_item_id'], 'quantity' => 3, 'unit_price' => 20]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/confirm"))->assertOk();
        $note = (string) $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/deliver"))->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note}/post"))->assertOk();
        $fromNote = (string) $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note}/invoice"))->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$fromNote}/post"))->assertOk();

        $lineId = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$direct}"))->json('data.lines.0.id');
        $this->createAndPost('/sales-credit-notes', [
            'sales_invoice_id' => $direct,
            'type' => 'return',
            'credit_date' => now()->toDateString(),
            'reason' => 'Return',
            'lines' => [['sales_invoice_line_id' => $lineId, 'quantity' => 1, 'warehouse_id' => $this->catalog['warehouse_id'], 'restock' => true]],
        ], 'sales-credit-notes');

        return $direct;
    }

    /**
     * Create and post a document; returns its id.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createAndPost(string $endpoint, array $payload, string $resource): string
    {
        $id = (string) $this->asTenantRequest($this->token)->postJson($this->tenantUrl($endpoint), $payload)->assertCreated()->json('data.id');
        $response = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/{$resource}/{$id}/post"));
        $this->assertSame(200, $response->status(), (string) $response->getContent());

        return $id;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function report(string $name, array $query = []): TestResponse
    {
        $query = array_merge(['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()], $query);

        return $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/'.$name.'?'.http_build_query($query)))
            ->assertOk();
    }

    private function body(TestResponse $response): string
    {
        $base = $response->baseResponse;
        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create(['name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true]);
        CompanySetting::singleton()->update(['primary_currency_id' => $usd->id, 'tax_enabled' => false, 'price_decimal_places' => 2]);
        app(CompanySettingService::class)->forgetCache();
        app(ChartOfAccountsService::class)->seedFromTemplate();

        $warehouse = Warehouse::query()->create([
            'name' => 'Main WH', 'shortcut_name' => 'MWH', 'type' => WarehouseType::Central, 'manager_id' => $this->tenantUser->id,
            'is_active' => true, 'is_default' => true, 'is_default_sales' => true, 'is_default_production' => false,
            'is_default_purchase' => true, 'is_default_storage' => false,
        ]);
        $ea = UnitOfMeasurement::query()->where('code', 'EA')->value('id');
        $make = function (string $code, bool $lots) use ($ea): Item {
            $item = Item::query()->create([
                'name' => 'Item '.$code, 'item_code' => $code,
                'item_type_id' => ItemType::query()->where('code', 'INVENTORY')->value('id'),
                'unit_group_id' => UnitGroup::query()->where('code', 'COUNT')->value('id'),
                'base_uom_id' => $ea, 'track_inventory' => true, 'track_lots' => $lots,
                'allow_sale' => true, 'allow_purchase' => true, 'is_active' => true,
            ]);
            ItemUom::query()->create(['item_id' => $item->id, 'uom_id' => $ea, 'conversion_factor' => 1, 'selling_price' => 20, 'cost_price' => 5, 'is_base' => true, 'is_default_sale' => true]);

            return $item;
        };
        $stock = $make('STK-R', false);
        $lotItem = $make('LOT-ITEM', true);
        $lot = InventoryLot::query()->create(['item_id' => $lotItem->id, 'lot_number' => 'LOT-1', 'expiry_date' => now()->addDays(10)->toDateString()]);

        $movements = app(StockMovementService::class);
        $movements->post(StockMovementData::forOpening(itemId: (string) $stock->id, warehouseId: (int) $warehouse->id, quantityDelta: '10', unitCost: '5', itemUomId: null, notes: 'opening', userId: null));
        $movements->post(StockMovementData::forOpening(itemId: (string) $lotItem->id, warehouseId: (int) $warehouse->id, quantityDelta: '4', unitCost: '2', itemUomId: null, notes: 'opening', userId: null, lotId: (int) $lot->id));

        $cash = PaymentMethod::query()->create(['code' => 'CASH', 'name' => 'Cash', 'type' => 'cash', 'requires_reference' => false, 'is_active' => true]);
        $customer = Customer::query()->create(['name' => 'Report Customer', 'type' => 'individual', 'status' => 'active']);

        return [
            'usd_id' => (int) $usd->id,
            'warehouse_id' => (int) $warehouse->id,
            'stock_item_id' => (string) $stock->id,
            'customer_id' => (string) $customer->id,
            'cash_method_id' => (int) $cash->id,
        ];
    }
}
