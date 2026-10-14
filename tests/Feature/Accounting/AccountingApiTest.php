<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\AccountingBackfillService;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=AccountingApiTest
 */
#[Group('tenant-db')]
class AccountingApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const RECEIVABLE = '4111';

    private const PAYABLE = '4011';

    private const INVENTORY = '3710';

    private const GRNI = '4018';

    private const REVENUE = '7011';

    private const COGS = '6053';

    private const VAT_OUTPUT = '44270';

    private const VAT_INPUT = '44210';

    private const PRICE_DIFFERENCE = '6014';

    private const CASH = '5310';

    private const SHORTAGE = '6613';

    private const EXCHANGE_GAIN = '7751';

    private const CAPITAL = '1013';

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('accounting_v1');
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

    public function test_seeding_creates_the_template_and_reseeding_keeps_local_changes(): void
    {
        $template = config('chart_of_accounts.templates.lb');

        $this->tenant->run(function () use ($template): void {
            $this->assertSame(count($template['accounts']), Account::query()->count());
            $this->assertSame(count($template['system_accounts']), AccountMapping::query()->count());
        });

        $revenueId = $this->accountId(self::REVENUE);
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/accounts/{$revenueId}"), [
                'code' => self::REVENUE,
                'name' => 'Local sales',
                'parent_id' => $this->tenant->run(fn () => Account::query()->whereKey($revenueId)->value('parent_id')),
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'is_group' => false,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Local sales');

        $this->tenant->run(function () use ($template, $revenueId): void {
            $result = app(ChartOfAccountsService::class)->seedFromTemplate();
            $this->assertSame(0, $result['accounts_created']);
            $this->assertSame(0, $result['mappings_created']);
            $this->assertSame(count($template['accounts']), Account::query()->count());
            $this->assertSame('Local sales', Account::query()->whereKey($revenueId)->value('name'));
        });
    }

    public function test_account_rules_reject_bad_codes_group_posting_and_deleting_used_accounts(): void
    {
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounts'), [
                'code' => '9999',
                'name' => 'Wrong branch',
                'parent_id' => $this->accountId('70'),
                'type' => 'revenue',
                'normal_balance' => 'credit',
                'is_group' => false,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_CODE_PARENT_MISMATCH');

        $draft = $this->createManualEntry([
            ['account_id' => $this->accountId('531'), 'debit' => 10],
            ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 10],
        ], 422);
        $this->assertSame('ACCOUNT_NOT_POSTABLE', $draft->json('code'));

        $entryId = $this->createManualEntry([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 10],
            ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 10],
        ])->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$entryId}/post"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl('/accounts/'.$this->accountId(self::CAPITAL)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_IN_USE');
    }

    public function test_manual_entry_must_balance_and_reversal_returns_balances_to_zero(): void
    {
        $unbalanced = $this->createManualEntry([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 100],
            ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 90],
        ])->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$unbalanced}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'JOURNAL_ENTRY_UNBALANCED');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/journal-entries/{$unbalanced}/lines"), ['lines' => [
                ['account_id' => $this->accountId(self::CASH), 'debit' => 100],
                ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 100],
            ]])
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$unbalanced}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->assertBalance(100, self::CASH);
        $this->assertBalance(-100, self::CAPITAL);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$unbalanced}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.reversal_of_id', $unbalanced);

        $this->assertBalance(0, self::CASH);
        $this->assertBalance(0, self::CAPITAL);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/journal-entries/{$unbalanced}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
    }

    public function test_manual_entry_cannot_touch_the_receivable_control_account(): void
    {
        $response = $this->createManualEntry([
            ['account_id' => $this->accountId(self::RECEIVABLE), 'debit' => 10, 'party_type' => 'customer', 'party_id' => $this->catalog['customer_id']],
            ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 10],
        ], 422);

        $this->assertSame('ACCOUNT_CONTROLLED', $response->json('code'));
    }

    public function test_sales_invoice_posts_receivable_vat_revenue_and_cost(): void
    {
        $this->openingStock('10', '60');
        $this->postSalesInvoice(100);

        $this->assertBalance(111, self::RECEIVABLE);
        $this->assertBalance(-11, self::VAT_OUTPUT);
        $this->assertBalance(-100, self::REVENUE);
        $this->assertBalance(60, self::COGS);
        $this->assertBalance(-60, self::INVENTORY);

        $trial = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/trial-balance'))
            ->assertOk();
        $this->assertEqualsWithDelta(
            (float) $trial->json('data.totals.closing_debit'),
            (float) $trial->json('data.totals.closing_credit'),
            0.0001,
        );
        $this->assertEqualsWithDelta(171.0, (float) $trial->json('data.totals.period_debit'), 0.0001);
    }

    public function test_inclusive_pricing_posts_the_same_revenue_and_vat(): void
    {
        $this->tenant->run(function (): void {
            CompanySetting::singleton()->update(['tax_price_mode' => 'inclusive']);
            app(CompanySettingService::class)->forgetCache();
        });

        $this->postSalesInvoice(111, $this->catalog['service_item_id']);

        $this->assertBalance(111, self::RECEIVABLE);
        $this->assertBalance(-11, self::VAT_OUTPUT);
        $this->assertBalance(-100, self::REVENUE);
    }

    public function test_reversing_a_sales_invoice_returns_every_balance_to_zero(): void
    {
        $this->openingStock('10', '60');
        $id = $this->postSalesInvoice(100);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/reverse"))
            ->assertOk();

        foreach ([self::RECEIVABLE, self::VAT_OUTPUT, self::REVENUE, self::COGS, self::INVENTORY] as $code) {
            $this->assertBalance(0, $code);
        }
        $this->tenant->run(function () use ($id): void {
            $this->assertSame(2, JournalEntry::query()->where('source_id', $id)->count());
        });
    }

    public function test_goods_receipt_then_linked_purchase_invoice_clears_grni_and_posts_price_difference(): void
    {
        $receipt = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/goods-receipts'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'supplier_id' => $this->catalog['supplier_id'],
                'received_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_cost' => 50,
                ]],
            ])
            ->assertCreated();
        $receiptId = (string) $receipt->json('data.id');
        $receiptLineId = (int) $receipt->json('data.lines.0.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/goods-receipts/{$receiptId}/post"))
            ->assertOk();

        $this->assertBalance(50, self::INVENTORY);
        $this->assertBalance(-50, self::GRNI);

        $invoice = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'goods_receipt_id' => $receiptId,
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-02',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLineId,
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_price' => 52,
                ]],
            ])
            ->assertCreated();
        $invoiceId = (string) $invoice->json('data.id');

        $posted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$invoiceId}/post"))
            ->assertOk();
        $grand = (float) $posted->json('data.grand_total');
        $this->assertEqualsWithDelta(57.72, $grand, 0.001);

        $this->assertBalance(-$grand, self::PAYABLE);
        $this->assertBalance(0, self::GRNI);
        $this->assertBalance(2, self::PRICE_DIFFERENCE);
        $this->assertBalance(5.72, self::VAT_INPUT);
        $this->assertBalance(50, self::INVENTORY);
    }

    public function test_receipt_at_a_different_rate_posts_an_exchange_difference(): void
    {
        $invoiceId = $this->postSalesInvoice(350, $this->catalog['service_item_id'], [
            'currency_id' => $this->catalog['eur_id'],
            'exchange_rate' => 3.5,
        ]);

        $this->assertBalance(111, self::RECEIVABLE);

        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['eur_id'],
                'exchange_rate' => 2.8,
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-05',
                'amount' => 388.5,
                'allocations' => [['sales_invoice_id' => $invoiceId, 'amount' => 388.5]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))
            ->assertOk();

        $this->assertBalance(0, self::RECEIVABLE);
        $this->assertBalance(138.75, self::CASH);
        $this->assertBalance(-27.75, self::EXCHANGE_GAIN);
    }

    public function test_stock_count_shortage_posts_to_inventory_shortage(): void
    {
        $this->openingStock('10', '5');

        $countId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/stock-counts'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'count_date' => '2026-09-03',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'theoretical_quantity' => 10,
                    'counted_quantity' => 8,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/stock-counts/{$countId}/post"))
            ->assertOk();

        $this->assertBalance(10, self::SHORTAGE);
        $this->assertBalance(-10, self::INVENTORY);
    }

    public function test_tenant_without_the_module_posts_invoices_without_journal_entries(): void
    {
        $this->setAccountingModule(false);

        $this->postSalesInvoice(100, $this->catalog['service_item_id']);

        $this->tenant->run(function (): void {
            $this->assertSame(0, JournalEntry::query()->count());
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounts'))
            ->assertForbidden();
    }

    public function test_backfill_creates_missing_entries_once(): void
    {
        $this->setAccountingModule(false);
        $this->postSalesInvoice(100, $this->catalog['service_item_id']);
        $reversed = $this->postSalesInvoice(40, $this->catalog['service_item_id']);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$reversed}/reverse"))
            ->assertOk();
        $this->setAccountingModule(true);

        $this->tenant->run(function () use ($reversed): void {
            $this->assertSame(3, app(AccountingBackfillService::class)->run());
            $this->assertSame(0, app(AccountingBackfillService::class)->run());
            $this->assertTrue(JournalEntry::query()->where('source_id', $reversed)->where('source_event', 'reverse')->exists());
        });

        $this->assertBalance(111, self::RECEIVABLE);
        $this->assertBalance(-100, self::REVENUE);
    }

    public function test_receivable_per_customer_matches_the_customer_ledger(): void
    {
        $this->postSalesInvoice(100, $this->catalog['service_item_id']);
        $second = $this->postSalesInvoice(50, $this->catalog['service_item_id']);
        $third = $this->postSalesInvoice(20, $this->catalog['service_item_id']);

        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-05',
                'amount' => 40,
                'allocations' => [['sales_invoice_id' => $second, 'amount' => 40]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$third}/reverse"))->assertOk();

        $this->tenant->run(function (): void {
            $customer = Customer::query()->findOrFail($this->catalog['customer_id']);
            $ledger = (float) app(CustomerLedgerService::class)->balanceInCurrency($customer, $this->catalog['usd_id']);

            $receivable = (float) JournalEntryLine::query()
                ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
                ->where('je.status', '!=', 'draft')
                ->where('journal_entry_lines.party_type', 'customer')
                ->where('journal_entry_lines.party_id', $customer->id)
                ->selectRaw('COALESCE(SUM(journal_entry_lines.debit - journal_entry_lines.credit), 0) AS net')
                ->value('net');

            $this->assertEqualsWithDelta(166.5, $ledger, 0.001);
            $this->assertEqualsWithDelta($ledger, $receivable, 0.001);
        });

        $ledger = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/account-ledger?account_id='.$this->accountId(self::RECEIVABLE)))
            ->assertOk();
        $this->assertEqualsWithDelta(166.5, (float) $ledger->json('data.closing_balance'), 0.001);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function postSalesInvoice(float $price, ?string $itemId = null, array $overrides = []): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), array_merge([
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $itemId ?? $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_price' => $price,
                ]],
            ], $overrides))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        return (string) $id;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function createManualEntry(array $lines, int $status = 201): TestResponse
    {
        return $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/journal-entries'), [
                'entry_date' => '2026-09-01',
                'description' => 'Manual',
                'lines' => $lines,
            ])
            ->assertStatus($status);
    }

    private function openingStock(string $quantity, string $unitCost): void
    {
        $this->tenant->run(function () use ($quantity, $unitCost): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['stock_item_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: $quantity,
                unitCost: $unitCost,
                itemUomId: null,
                notes: 'test opening',
                userId: null,
            ));
        });
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

    private function accountId(string $code): int
    {
        return (int) $this->tenant->run(fn () => Account::query()->where('code', $code)->value('id'));
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
        $eur = Currency::query()->create([
            'name' => 'Euro',
            'code' => 'EUR',
            'iso_code' => 'EUR',
            'symbol' => '€',
            'is_active' => true,
        ]);

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => true,
            'tax_price_mode' => 'exclusive',
            'allow_negative_stock' => false,
        ]);
        app(CompanySettingService::class)->forgetCache();
        app(ExchangeRateService::class)->setPairRate($usd->id, $eur->id, 3.5);

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
            'abrv' => 'VAT11',
            'name' => 'VAT 11',
            'percentage' => 11,
            'is_default' => true,
            'is_active' => true,
        ]);
        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();

        $service = $this->makeItem('Service item', 'SVC-A', $serviceType->id, $unitGroup->id, $uom->id, $vat->id, false);
        $stock = $this->makeItem('Stock item', 'STK-A', $inventoryType->id, $unitGroup->id, $uom->id, $vat->id, true);

        $customer = Customer::query()->create([
            'name' => 'Accounting Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Accounting Supplier',
            'is_active' => true,
        ]);
        $cash = PaymentMethod::query()->create([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'supplier_id' => (string) $supplier->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
            'cash_method_id' => (int) $cash->id,
        ];
    }

    private function makeItem(string $name, string $code, int $typeId, int $unitGroupId, int $uomId, int $vatId, bool $trackInventory): Item
    {
        $item = Item::query()->create([
            'name' => $name,
            'item_code' => $code,
            'item_type_id' => $typeId,
            'unit_group_id' => $unitGroupId,
            'base_uom_id' => $uomId,
            'vat_group_id' => $vatId,
            'track_inventory' => $trackInventory,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
        ]);

        ItemUom::query()->create([
            'item_id' => $item->id,
            'uom_id' => $uomId,
            'conversion_factor' => 1,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        return $item;
    }
}
