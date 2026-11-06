<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\InventoryCostLayer;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Treasury\Models\ExpenseVoucherLine;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Landed costs: freight / customs bills spread over received goods.
 */
#[Group('tenant-db')]
class LandedCostApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const INVENTORY = '3710';

    private const COGS = '6053';

    private const CLEARING = '4017';

    private const PRICE_DIFFERENCE = '6014';

    private const CASH = '5310';

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('landed_cost_v1');
        BootstrapTenantItemTypes::dispatchSync($this->tenant);
        BootstrapTenantUnitCatalog::dispatchSync($this->tenant);
        $this->token = $this->tenantBearerToken();
        $this->catalog = $this->tenant->run(fn (): array => $this->seedCatalog());
        $this->catalog['cash_account_id'] = (int) $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/cash-bank-accounts'), [
            'code' => 'CASH1', 'name' => 'Main cash', 'type' => 'cash', 'currency_id' => $this->catalog['usd_id'],
            'account_id' => $this->accountId(self::CASH), 'opening_balance' => 1000000, 'opening_date' => '2026-01-01',
        ])->assertCreated()->json('data.id');
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_freight_bill_and_customs_voucher_are_spread_over_the_receipt(): void
    {
        $grn = $this->postReceipt([
            ['item_id' => $this->catalog['item_a'], 'quantity' => 100, 'unit_cost' => 200],
            ['item_id' => $this->catalog['item_b'], 'quantity' => 50, 'unit_cost' => 40],
        ]);
        $freightLine = $this->postFreightBill(1100);
        $customsLine = $this->postCustomsVoucher(2200);

        // Both bills wait on the clearing account.
        $this->assertBalance(3300, self::CLEARING);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/landed-costs/sources?type=charges'))
            ->assertOk()->assertJsonCount(2, 'data');

        $preview = $this->req()->postJson($this->tenantUrl('/landed-costs/preview'), $this->payload($grn, [
            ['source_type' => 'purchase_invoice_line', 'source_line_id' => $freightLine, 'method' => 'quantity'],
            ['source_type' => 'expense_voucher_line', 'source_line_id' => $customsLine, 'method' => 'value'],
        ]))->assertOk()->json('data');
        $this->assertSame('3300.0000', $preview['total_amount']);
        $this->assertSame('2733.3333', $preview['lines'][0]['added_amount']);
        $this->assertSame('566.6667', $preview['lines'][1]['added_amount']);
        $this->assertSame('227.333333', $preview['lines'][0]['landed_unit_cost']);

        $id = $this->createLandedCost($grn, [
            ['source_type' => 'purchase_invoice_line', 'source_line_id' => $freightLine, 'method' => 'quantity'],
            ['source_type' => 'expense_voucher_line', 'source_line_id' => $customsLine, 'method' => 'value'],
        ]);
        $posted = $this->req()->postJson($this->tenantUrl("/landed-costs/{$id}/post"))->assertOk();
        $this->assertMatchesRegularExpression('/^LC-[A-Z0-9]+-\d{4}-000001$/', (string) $posted->json('data.landed_cost_number'));

        $this->assertSame('227.333333', $this->unitCost('item_a'));
        $this->assertSame('51.333334', $this->unitCost('item_b'));
        $this->assertBalance(25300, self::INVENTORY);
        $this->assertBalance(0, self::CLEARING);

        // Nothing left to use, and the used bills cannot be reversed under it.
        $this->req()->getJson($this->tenantUrl('/landed-costs/sources?type=charges'))->assertOk()->assertJsonCount(0, 'data');
        $this->req()->postJson($this->tenantUrl("/stock/goods-receipts/{$grn}/reverse"))
            ->assertUnprocessable()->assertJsonPath('code', 'DOCUMENT_HAS_LANDED_COST');
        $voucherId = $this->tenant->run(fn (): string => (string) ExpenseVoucherLine::query()->whereKey($customsLine)->value('expense_voucher_id'));
        $this->req()->postJson($this->tenantUrl("/expense-vouchers/{$voucherId}/reverse"))
            ->assertUnprocessable()->assertJsonPath('code', 'DOCUMENT_HAS_LANDED_COST');

        $pdf = $this->req()->get($this->tenantUrl("/landed-costs/{$id}/pdf"))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_goods_partly_sold_split_between_stock_and_cost_of_sales_and_reversal_restores_them(): void
    {
        $grn = $this->postReceipt([['item_id' => $this->catalog['item_a'], 'quantity' => 100, 'unit_cost' => 200]]);
        $this->issue('item_a', '-40');
        $charge = $this->postCustomsVoucher(4000);

        $id = $this->createLandedCost($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'quantity']]);
        $this->req()->postJson($this->tenantUrl("/landed-costs/{$id}/post"))->assertOk()
            ->assertJsonPath('data.charges.0.allocations', fn ($a) => array_values($a)[0]['inventory_amount'] === '2400.0000' && array_values($a)[0]['cogs_amount'] === '1600.0000');

        $this->assertSame('240.000000', $this->unitCost('item_a'));
        $this->assertSame('14400.0000', $this->value('item_a'));
        $this->assertBalance(0, self::CLEARING);
        $this->assertLandedJournal($id, 'post', inventory: 2400, cogs: 1600);

        // Sell 30 more, then reverse: 30 of the 60 are still here, so half of the stock share comes off stock.
        $this->issue('item_a', '-30');
        $this->req()->postJson($this->tenantUrl("/landed-costs/{$id}/reverse"))->assertOk()->assertJsonPath('data.status', 'reversed');
        $this->assertSame('200.000000', $this->unitCost('item_a'));
        $this->assertLandedJournal($id, 'reverse', inventory: -1200, cogs: -2800);
        $this->assertBalance(4000, self::CLEARING);

        // The GRN is free again.
        $this->req()->getJson($this->tenantUrl('/landed-costs/sources?type=charges'))->assertOk()->assertJsonPath('data.0.open_amount', '4000.0000');
    }

    public function test_fifo_puts_the_cost_on_the_receipt_layer_only(): void
    {
        $this->tenant->run(function (): void {
            Item::query()->whereKey($this->catalog['item_a'])->update(['costing_method' => 'fifo']);
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['item_a'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '10',
                unitCost: '100',
                itemUomId: null,
                notes: 'opening',
                userId: null,
            ));
        });
        $grn = $this->postReceipt([['item_id' => $this->catalog['item_a'], 'quantity' => 10, 'unit_cost' => 200]]);
        $charge = $this->postCustomsVoucher(1000);

        $id = $this->createLandedCost($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'value']]);
        $this->req()->postJson($this->tenantUrl("/landed-costs/{$id}/post"))->assertOk();

        $costs = $this->tenant->run(fn (): array => InventoryCostLayer::query()->where('item_id', $this->catalog['item_a'])->orderBy('id')->pluck('unit_cost')->map(fn ($c) => (string) $c)->all());
        $this->assertSame(['100.000000', '300.000000'], $costs);
        $this->assertSame('4000.0000', $this->value('item_a'));
    }

    public function test_standard_costing_books_a_price_difference_and_keeps_the_cost(): void
    {
        $this->tenant->run(fn () => Item::query()->whereKey($this->catalog['item_a'])->update(['costing_method' => 'standard']));
        $grn = $this->postReceipt([['item_id' => $this->catalog['item_a'], 'quantity' => 10, 'unit_cost' => 150]]);
        $before = $this->unitCost('item_a');
        $charge = $this->postCustomsVoucher(500);

        $id = $this->createLandedCost($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'quantity']]);
        $this->req()->postJson($this->tenantUrl("/landed-costs/{$id}/post"))->assertOk();

        $this->assertSame($before, $this->unitCost('item_a'));
        $this->assertLandedJournal($id, 'post', inventory: 0, cogs: 0, variance: 500);
    }

    public function test_methods_validation_partial_use_foreign_currency_and_direct_invoice_receipts(): void
    {
        $grn = $this->postReceipt([
            ['item_id' => $this->catalog['item_a'], 'quantity' => 10, 'unit_cost' => 100],
            ['item_id' => $this->catalog['item_b'], 'quantity' => 10, 'unit_cost' => 100],
        ]);
        $charge = $this->postCustomsVoucher(300);

        // Weight is missing on the items.
        $this->req()->postJson($this->tenantUrl('/landed-costs'), $this->payload($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'weight']]))
            ->assertUnprocessable()->assertJsonPath('code', 'LANDED_COST_WEIGHT_MISSING');
        $this->tenant->run(function (): void {
            Item::query()->whereKey($this->catalog['item_a'])->update(['weight' => 2]);
            Item::query()->whereKey($this->catalog['item_b'])->update(['weight' => 1]);
        });
        $byWeight = $this->req()->postJson($this->tenantUrl('/landed-costs/preview'), $this->payload($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'weight']]))
            ->assertOk()->json('data.lines');
        $this->assertSame(['200.0000', '100.0000'], array_column($byWeight, 'added_amount'));

        // Manual shares must add up; more than the open amount is refused.
        $keys = array_column($byWeight, 'key');
        $this->req()->postJson($this->tenantUrl('/landed-costs'), $this->payload($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'manual', 'manual' => [$keys[0] => 100, $keys[1] => 100]]]))
            ->assertUnprocessable()->assertJsonPath('code', 'LANDED_COST_MANUAL_MISMATCH');
        $this->req()->postJson($this->tenantUrl('/landed-costs'), $this->payload($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'value', 'amount' => 301]]))
            ->assertUnprocessable()->assertJsonPath('code', 'LANDED_COST_CHARGE_EXCEEDS_OPEN');

        // A charge can be used in two landed costs: 100 now (manual), the remaining 200 later.
        $first = $this->createLandedCost($grn, [['source_type' => 'expense_voucher_line', 'source_line_id' => $charge, 'method' => 'manual', 'amount' => 100, 'manual' => [$keys[0] => 60, $keys[1] => 40]]]);
        $this->req()->postJson($this->tenantUrl("/landed-costs/{$first}/post"))->assertOk();
        $this->req()->getJson($this->tenantUrl('/landed-costs/sources?type=charges'))->assertOk()->assertJsonPath('data.0.open_amount', '200.0000');

        // Freight billed in EUR (1 USD = 3.5 EUR): 350 EUR is 100 USD; a direct purchase invoice is a valid receipt.
        $eurLine = $this->postFreightBill(350, 'eur_id');
        $direct = $this->req()->postJson($this->tenantUrl('/purchase-invoices'), [
            'supplier_id' => $this->catalog['supplier_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['usd_id'],
            'invoice_date' => now()->toDateString(),
            'lines' => [['item_id' => $this->catalog['item_b'], 'quantity' => 5, 'unit_price' => 20]],
        ])->assertCreated()->json('data.id');
        $this->req()->postJson($this->tenantUrl("/purchase-invoices/{$direct}/post"))->assertOk();
        $preview = $this->req()->postJson($this->tenantUrl('/landed-costs/preview'), [
            'receipts' => [['source_type' => 'purchase_invoice', 'source_id' => $direct]],
            'charges' => [['source_type' => 'purchase_invoice_line', 'source_line_id' => $eurLine, 'method' => 'value']],
        ])->assertOk()->json('data');
        $this->assertSame('100.0000', $preview['total_amount']);
        $this->assertSame('20.000000', $preview['lines'][0]['added_per_unit']);

        // Only service items can be landed-cost items.
        $this->req()->putJson($this->tenantUrl('/items/'.$this->catalog['item_a']), ['is_landed_cost' => true])
            ->assertUnprocessable()->assertJsonPath('code', 'ITEM_LANDED_COST_MUST_BE_SERVICE');
    }

    // ---------------------------------------------------------------- helpers

    private function req(): self
    {
        return $this->asTenantRequest($this->token);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function postReceipt(array $lines): string
    {
        $id = (string) $this->req()->postJson($this->tenantUrl('/stock/goods-receipts'), [
            'warehouse_id' => $this->catalog['warehouse_id'],
            'supplier_id' => $this->catalog['supplier_id'],
            'received_date' => now()->toDateString(),
            'lines' => $lines,
        ])->assertCreated()->json('data.id');
        $this->req()->postJson($this->tenantUrl("/stock/goods-receipts/{$id}/post"))->assertOk();

        return $id;
    }

    private function postFreightBill(float $amount, string $currencyKey = 'usd_id'): int
    {
        $response = $this->req()->postJson($this->tenantUrl('/purchase-invoices'), [
            'supplier_id' => $this->catalog['shipper_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog[$currencyKey],
            'invoice_date' => now()->toDateString(),
            'lines' => [['item_id' => $this->catalog['freight_id'], 'quantity' => 1, 'unit_price' => $amount]],
        ])->assertCreated();
        $id = (string) $response->json('data.id');
        $this->req()->postJson($this->tenantUrl("/purchase-invoices/{$id}/post"))->assertOk();

        return (int) $response->json('data.lines.0.id');
    }

    private function postCustomsVoucher(float $amount): int
    {
        $response = $this->req()->postJson($this->tenantUrl('/expense-vouchers'), [
            'voucher_date' => now()->toDateString(),
            'cash_bank_account_id' => $this->catalog['cash_account_id'],
            'payee' => 'Customs',
            'lines' => [['account_id' => $this->accountId(self::CLEARING), 'description' => 'Customs duty', 'amount' => $amount]],
        ])->assertCreated();
        $id = (string) $response->json('data.id');
        $this->req()->postJson($this->tenantUrl("/expense-vouchers/{$id}/post"))->assertOk();

        return (int) $response->json('data.lines.0.id');
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     * @return array<string, mixed>
     */
    private function payload(string $grn, array $charges): array
    {
        return [
            'cost_date' => now()->toDateString(),
            'reference' => 'BL-1001',
            'receipts' => [['source_type' => 'goods_receipt', 'source_id' => $grn]],
            'charges' => $charges,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $charges
     */
    private function createLandedCost(string $grn, array $charges): string
    {
        return (string) $this->req()->postJson($this->tenantUrl('/landed-costs'), $this->payload($grn, $charges))
            ->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    private function issue(string $key, string $qty): void
    {
        $this->tenant->run(fn () => app(StockMovementService::class)->post(StockMovementData::forAdjustment(
            itemId: $this->catalog[$key],
            warehouseId: $this->catalog['warehouse_id'],
            quantityDelta: $qty,
            unitCost: null,
            itemUomId: null,
            notes: 'issue',
            userId: null,
        )));
    }

    private function unitCost(string $key): string
    {
        return $this->tenant->run(fn (): string => (string) StockBalance::query()->where('item_id', $this->catalog[$key])->value('unit_cost'));
    }

    private function value(string $key): string
    {
        return $this->tenant->run(fn (): string => (string) StockBalance::query()->where('item_id', $this->catalog[$key])->value('inventory_value'));
    }

    private function accountId(string $code): int
    {
        return $this->tenant->run(fn (): int => (int) Account::query()->where('code', $code)->value('id'));
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

    private function assertLandedJournal(string $id, string $event, float $inventory, float $cogs, float $variance = 0): void
    {
        $nets = $this->tenant->run(function () use ($id, $event): array {
            $rows = DB::table('journal_entry_lines as l')
                ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
                ->join('accounts as a', 'a.id', '=', 'l.account_id')
                ->where('e.source_type', 'landed_cost')
                ->where('e.source_id', $id)
                ->where('e.source_event', $event)
                ->groupBy('a.code')
                ->selectRaw('a.code, SUM(l.debit) - SUM(l.credit) as net')
                ->pluck('net', 'code');

            return array_map('floatval', $rows->all());
        });
        $this->assertEqualsWithDelta($inventory, $nets[self::INVENTORY] ?? 0, 0.0001, 'inventory');
        $this->assertEqualsWithDelta($cogs, $nets[self::COGS] ?? 0, 0.0001, 'cogs');
        $this->assertEqualsWithDelta($variance, $nets[self::PRICE_DIFFERENCE] ?? 0, 0.0001, 'price difference');
        $this->assertEqualsWithDelta(-($inventory + $cogs + $variance), $nets[self::CLEARING] ?? 0, 0.0001, 'clearing');
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create(['name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true]);
        $eur = Currency::query()->create(['name' => 'Euro', 'code' => 'EUR', 'iso_code' => 'EUR', 'symbol' => '€', 'is_active' => true]);
        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => false,
            'allow_negative_stock' => false,
            'price_decimal_places' => 2,
        ]);
        app(CompanySettingService::class)->forgetCache();
        app(ExchangeRateService::class)->setPairRate($usd->id, $eur->id, '3.5');
        app(ChartOfAccountsService::class)->seedFromTemplate();

        $warehouse = Warehouse::query()->create([
            'name' => 'Main WH', 'shortcut_name' => 'MWH', 'type' => WarehouseType::Central, 'manager_id' => $this->tenantUser->id,
            'is_active' => true, 'is_default' => true, 'is_default_sales' => true, 'is_default_production' => false,
            'is_default_purchase' => true, 'is_default_storage' => false,
        ]);

        $group = UnitGroup::query()->where('code', 'COUNT')->value('id');
        $ea = UnitOfMeasurement::query()->where('code', 'EA')->value('id');
        $make = function (string $name, string $code, bool $stock, bool $landed = false) use ($group, $ea): string {
            $item = Item::query()->create([
                'name' => $name, 'item_code' => $code,
                'item_type_id' => ItemType::query()->where('code', $stock ? 'INVENTORY' : 'SERVICE')->value('id'),
                'unit_group_id' => $group, 'base_uom_id' => $ea,
                'track_inventory' => $stock, 'track_lots' => false, 'allow_sale' => true, 'allow_purchase' => true, 'is_active' => true,
                'is_landed_cost' => $landed,
            ]);
            ItemUom::query()->create(['item_id' => $item->id, 'uom_id' => $ea, 'conversion_factor' => 1, 'selling_price' => 10, 'cost_price' => 150, 'is_base' => true, 'is_default_sale' => true, 'is_default_purchase' => true]);

            return (string) $item->id;
        };

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'item_a' => $make('Phone', 'PHN', true),
            'item_b' => $make('Case', 'CSE', true),
            'freight_id' => $make('Sea freight', 'FRT', false, true),
            'supplier_id' => (string) Supplier::query()->create(['name' => 'Factory', 'is_active' => true])->id,
            'shipper_id' => (string) Supplier::query()->create(['name' => 'Shipping line', 'is_active' => true])->id,
        ];
    }
}
