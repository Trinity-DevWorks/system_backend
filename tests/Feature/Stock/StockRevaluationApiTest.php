<?php

declare(strict_types=1);

namespace Tests\Feature\Stock;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\Enums\InventoryCostingMethod;
use App\Modules\Inventory\Stock\Models\InventoryCostLayer;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=StockRevaluationApiTest
 */
#[Group('tenant-db')]
class StockRevaluationApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const INVENTORY = '3710';

    private const SURPLUS = '7614';

    private const SHORTAGE = '6613';

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('stock_reval_v1');
        BootstrapTenantItemTypes::dispatchSync($this->tenant);
        BootstrapTenantUnitCatalog::dispatchSync($this->tenant);
        $this->token = $this->tenantBearerToken();
        $this->catalog = $this->tenant->run(fn (): array => $this->seedCatalog());
        $this->tenant->run(fn () => app(ChartOfAccountsService::class)->seedFromTemplate());
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_revalue_increase_updates_balance_movement_and_journal(): void
    {
        $this->seedOnHand(10, 5);

        $id = $this->createAndPostRevaluation(7);

        $this->tenant->run(function () use ($id): void {
            $balance = StockBalance::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->whereNull('lot_id')
                ->firstOrFail();

            $this->assertSame('10.000000', (string) $balance->quantity);
            $this->assertSame('7.0000', (string) $balance->unit_cost);
            $this->assertSame('70.0000', (string) $balance->inventory_value);

            $movement = StockMovement::query()
                ->where('reference_type', 'stock_revaluation')
                ->where('reference_id', $id)
                ->where('notes', 'not like', 'Reverse%')
                ->firstOrFail();

            $this->assertSame('0.000000', (string) $movement->quantity_delta);
            $this->assertSame('20.0000', (string) $movement->value_delta);
            $this->assertSame('7.0000', (string) $movement->unit_cost);
            $this->assertSame('revaluation', $movement->type->value);
        });

        $this->assertBalance(20, self::INVENTORY);
        $this->assertBalance(-20, self::SURPLUS);
    }

    public function test_revalue_decrease_posts_shortage(): void
    {
        $this->seedOnHand(10, 5);

        $this->createAndPostRevaluation(3);

        $this->tenant->run(function (): void {
            $balance = StockBalance::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->whereNull('lot_id')
                ->firstOrFail();

            $this->assertSame('3.0000', (string) $balance->unit_cost);
            $this->assertSame('30.0000', (string) $balance->inventory_value);

            $movement = StockMovement::query()
                ->where('reference_type', 'stock_revaluation')
                ->where('notes', 'not like', 'Reverse%')
                ->firstOrFail();

            $this->assertSame('-20.0000', (string) $movement->value_delta);
        });

        $this->assertBalance(20, self::SHORTAGE);
        $this->assertBalance(-20, self::INVENTORY);
    }

    public function test_zero_on_hand_and_same_cost_are_rejected(): void
    {
        $emptyId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/revaluations'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'revaluation_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'new_unit_cost' => 7,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/revaluations/{$emptyId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_REVALUATION_NO_QTY');

        $this->seedOnHand(10, 5);

        $sameCostId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/revaluations'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'revaluation_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'new_unit_cost' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/revaluations/{$sameCostId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_REVALUATION_NO_CHANGE');
    }

    public function test_draft_does_not_change_balance(): void
    {
        $this->seedOnHand(10, 5);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/revaluations'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'revaluation_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'new_unit_cost' => 7,
                ]],
            ])
            ->assertCreated();

        $this->tenant->run(function (): void {
            $balance = StockBalance::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->whereNull('lot_id')
                ->firstOrFail();

            $this->assertSame('5.0000', (string) $balance->unit_cost);
            $this->assertSame('50.0000', (string) $balance->inventory_value);
            $this->assertSame(0, StockMovement::query()->where('type', 'revaluation')->count());
        });
    }

    public function test_reverse_restores_cost_and_nets_journal(): void
    {
        $this->seedOnHand(10, 5);
        $id = $this->createAndPostRevaluation(7);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/revaluations/{$id}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->tenant->run(function (): void {
            $balance = StockBalance::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->whereNull('lot_id')
                ->firstOrFail();

            $this->assertSame('10.000000', (string) $balance->quantity);
            $this->assertSame('5.0000', (string) $balance->unit_cost);
            $this->assertSame('50.0000', (string) $balance->inventory_value);
        });

        $this->assertBalance(0, self::INVENTORY);
        $this->assertBalance(0, self::SURPLUS);
    }

    public function test_fifo_revalue_collapses_to_one_layer(): void
    {
        $this->tenant->run(function (): void {
            Item::query()->whereKey($this->catalog['stock_item_id'])->update([
                'costing_method' => InventoryCostingMethod::Fifo->value,
            ]);
        });

        $openingId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/opening-stocks'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'opening_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 10,
                    'unit_cost' => 5,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/opening-stocks/{$openingId}/post"))
            ->assertOk();

        $this->createAndPostRevaluation(7);

        $this->tenant->run(function (): void {
            $layers = InventoryCostLayer::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->where('quantity_remaining', '>', 0)
                ->get();

            $this->assertCount(1, $layers);
            $this->assertSame('10.000000', (string) $layers->first()->quantity_remaining);
            $this->assertSame('7.0000', (string) $layers->first()->unit_cost);
        });
    }

    public function test_without_accounting_updates_stock_only(): void
    {
        $this->setAccountingModule(false);
        $this->seedOnHand(10, 5);
        $id = $this->createAndPostRevaluation(7);

        $this->tenant->run(function () use ($id): void {
            $balance = StockBalance::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->whereNull('lot_id')
                ->firstOrFail();

            $this->assertSame('7.0000', (string) $balance->unit_cost);
            $this->assertSame('70.0000', (string) $balance->inventory_value);

            $this->assertSame(0, JournalEntry::query()
                ->where('source_type', 'stock_revaluation')
                ->where('source_id', $id)
                ->count());
        });
    }

    public function test_balance_hint_returns_current_snapshot(): void
    {
        $this->seedOnHand(10, 5);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/stock/revaluations/balance-hint?'.http_build_query([
                'warehouse_id' => $this->catalog['warehouse_id'],
                'item_id' => $this->catalog['stock_item_id'],
            ])))
            ->assertOk()
            ->assertJsonPath('data.quantity', '10.000000')
            ->assertJsonPath('data.unit_cost', '5.0000')
            ->assertJsonPath('data.inventory_value', '50.0000');
    }

    private function seedOnHand(float $qty, float $unitCost): void
    {
        $openingId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/opening-stocks'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'opening_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/opening-stocks/{$openingId}/post"))
            ->assertOk();

        // Opening posts inventory; clear journals so revaluation assertions are isolated.
        $this->tenant->run(function (): void {
            JournalEntryLine::query()->delete();
            JournalEntry::query()->delete();
        });
    }

    private function createAndPostRevaluation(float $newUnitCost): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/revaluations'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'revaluation_date' => '2026-09-02',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'new_unit_cost' => $newUnitCost,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/revaluations/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        return (string) $id;
    }

    private function setAccountingModule(bool $enabled): void
    {
        $service = app(ModuleEntitlementService::class);
        $codes = collect(config('modules.catalog'))->keys()
            ->reject(fn (string $code): bool => ! $enabled && $code === 'accounting')
            ->values()
            ->all();
        $service->syncTenantModules($this->tenant, $codes);
    }

    private function assertBalance(float $expected, string $code): void
    {
        $actual = $this->tenant->run(function () use ($code): float {
            $id = (int) Account::query()->where('code', $code)->value('id');
            $sum = app(AccountBalanceService::class)->totals()[$id] ?? ['debit' => '0', 'credit' => '0'];

            return (float) $sum['debit'] - (float) $sum['credit'];
        });

        $this->assertEqualsWithDelta($expected, $actual, 0.0001, "Balance of account {$code}");
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create([
            'name' => 'US Dollar',
            'code' => 'USD',
            'iso_code' => 'USD',
            'symbol' => '$',
            'is_active' => true,
        ]);

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => false,
            'allow_negative_stock' => false,
            'inventory_costing_method' => InventoryCostingMethod::MovingAverage->value,
        ]);
        app(CompanySettingService::class)->forgetCache();

        $warehouse = Warehouse::query()->create([
            'name' => 'Main WH',
            'shortcut_name' => 'MWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => true,
            'is_default_production' => false,
            'is_default_purchase' => true,
            'is_default_storage' => false,
        ]);

        $vat = VatGroup::query()->create([
            'abrv' => 'VAT0',
            'name' => 'VAT 0',
            'percentage' => 0,
            'is_default' => true,
            'is_active' => true,
        ]);
        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();

        $item = Item::query()->create([
            'name' => 'Stock item',
            'item_code' => 'STK-R',
            'item_type_id' => $inventoryType->id,
            'unit_group_id' => $unitGroup->id,
            'base_uom_id' => $uom->id,
            'vat_group_id' => $vat->id,
            'track_inventory' => true,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
            'costing_method' => InventoryCostingMethod::MovingAverage->value,
        ]);

        ItemUom::query()->create([
            'item_id' => $item->id,
            'uom_id' => $uom->id,
            'conversion_factor' => 1,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        return [
            'warehouse_id' => (int) $warehouse->id,
            'stock_item_id' => (string) $item->id,
        ];
    }
}
