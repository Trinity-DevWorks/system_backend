<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\AccountingBackfillService;
use App\Modules\Accounting\Services\ChartOfAccountsService;
use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerBalance;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\StockAdjustmentReason;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        Carbon::setTestNow();
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_seeding_creates_the_template_and_reseeding_keeps_local_changes(): void
    {
        $template = config('chart_of_accounts.templates.lb');

        $this->tenant->run(function () use ($template): void {
            $this->assertSame(count($template['accounts']), Account::query()->count());
            $this->assertSame(count($template['system_accounts']), AccountMapping::query()->count());
            $this->assertSame('lb', CompanySetting::singleton()->chart_template);
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

        $this->tenant->run(function () use ($revenueId): void {
            Account::query()->whereKey($revenueId)->update(['name_ar' => 'مبيعات محلية']);
            $blank = Account::query()->where('code', '701')->firstOrFail();
            $blank->update(['name_ar' => null]);
        });

        $this->tenant->run(function () use ($template, $revenueId): void {
            $result = app(ChartOfAccountsService::class)->seedFromTemplate();
            $this->assertSame(0, $result['accounts_created']);
            $this->assertSame(0, $result['mappings_created']);
            $this->assertGreaterThan(0, $result['name_ar_filled']);
            $this->assertSame(count($template['accounts']), Account::query()->count());
            $revenue = Account::query()->whereKey($revenueId)->firstOrFail();
            $this->assertSame('Local sales', $revenue->name);
            $this->assertSame('مبيعات محلية', $revenue->name_ar);
            $this->assertSame('مبيعات البضائع', Account::query()->where('code', '701')->value('name_ar'));
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

    public function test_purchase_invoice_at_another_rate_than_the_order_clears_grni_at_the_receipt_value(): void
    {
        $orderId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/purchase-orders'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['eur_id'],
                'order_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 2,
                    'unit_price' => 70,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/purchase-orders/{$orderId}/confirm"))
            ->assertOk();

        // 70 EUR at the order rate of 3.5 EUR per USD is a primary cost of 20.
        $receipt = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/goods-receipts'), [
                'purchase_order_id' => $orderId,
                'received_date' => '2026-09-01',
            ])
            ->assertCreated();
        $this->assertEqualsWithDelta(20, (float) $receipt->json('data.lines.0.unit_cost'), 0.0001);
        $receiptId = (string) $receipt->json('data.id');
        $receiptLineId = (int) $receipt->json('data.lines.0.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/goods-receipts/{$receiptId}/post"))
            ->assertOk();
        $this->assertBalance(40, self::INVENTORY);
        $this->assertBalance(-40, self::GRNI);

        // The supplier bills the same 140 EUR, but the euro moved to 2.8: 50 USD net, 5.5 VAT.
        $invoiceId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'goods_receipt_id' => $receiptId,
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['eur_id'],
                'exchange_rate' => 2.8,
                'invoice_date' => '2026-09-03',
                'lines' => [[
                    'goods_receipt_line_id' => $receiptLineId,
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 2,
                    'unit_price' => 70,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$invoiceId}/post"))
            ->assertOk();

        $this->assertBalance(-55.5, self::PAYABLE);
        $this->assertBalance(0, self::GRNI);
        // GRNI clears at the receipt value (40); the 10 exchange difference goes to price difference.
        $this->assertBalance(10, self::PRICE_DIFFERENCE);
        $this->assertBalance(5.5, self::VAT_INPUT);
        $this->assertBalance(40, self::INVENTORY);
        $this->assertInventoryMatchesStock();
    }

    public function test_journal_entries_carry_the_document_branch(): void
    {
        [$homeId, $eastId] = $this->tenant->run(function (): array {
            $home = Branch::query()->where('is_default', true)->firstOrFail();
            $east = Branch::query()->create([
                'name' => 'East',
                'shortcut_name' => 'EST',
                'is_active' => true,
                'is_default' => false,
            ]);

            return [(int) $home->id, (int) $east->id];
        });

        $this->withHeaders(['X-Branch-Id' => (string) $eastId]);
        $invoiceId = $this->postSalesInvoice(100, $this->catalog['service_item_id']);
        $receiptId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 111,
                'allocations' => [['sales_invoice_id' => $invoiceId, 'amount' => 111]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $eastId)
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))
            ->assertOk();

        $this->withHeaders(['X-Branch-Id' => (string) $homeId]);
        $purchaseId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $homeId)
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$purchaseId}/post"))
            ->assertOk();

        $this->withHeaders(['X-Branch-Id' => (string) $eastId]);
        $paymentId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 55.5,
                'allocations' => [['purchase_invoice_id' => $purchaseId, 'amount' => 55.5]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.branch_id', $eastId)
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/supplier-payments/{$paymentId}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($invoiceId, $receiptId, $purchaseId, $paymentId, $homeId, $eastId): void {
            $branches = fn (string $id): array => JournalEntry::query()
                ->where('source_id', $id)
                ->pluck('branch_id')
                ->map(fn ($branch): ?int => $branch !== null ? (int) $branch : null)
                ->unique()
                ->values()
                ->all();

            $this->assertSame([$eastId], $branches($invoiceId));
            $this->assertSame([$eastId], $branches($receiptId));
            $this->assertSame([$homeId], $branches($purchaseId));
            $this->assertSame([$eastId], $branches($paymentId));
        });
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

    public function test_transfer_write_off_brings_inventory_in_line_with_stock(): void
    {
        $destinationId = $this->tenant->run(function (): int {
            return (int) Warehouse::query()->create([
                'name' => 'Shop WH',
                'shortcut_name' => 'SWH',
                'type' => WarehouseType::Central,
                'manager_id' => $this->tenantUser->id,
                'is_active' => true,
                'is_default' => false,
                'is_default_sales' => false,
                'is_default_production' => false,
                'is_default_purchase' => false,
                'is_default_storage' => true,
            ])->id;
        });
        $reasonId = $this->tenant->run(fn (): int => (int) StockAdjustmentReason::query()->create([
            'code' => 'WO',
            'name' => 'Write off',
            'direction' => 'decrease',
            'is_active' => true,
        ])->id);

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

        $transfer = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/transfers'), [
                'from_warehouse_id' => $this->catalog['warehouse_id'],
                'to_warehouse_id' => $destinationId,
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 10,
                ]],
            ])
            ->assertCreated()
            ->json('data');
        $transferId = $transfer['id'];
        $lineId = $transfer['lines'][0]['id'];

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/transfers/{$transferId}/dispatch"))
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/transfers/{$transferId}/receive"), [
                'lines' => [['stock_transfer_line_id' => $lineId, 'quantity' => 6]],
            ])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/transfers/{$transferId}/close-open"), [
                'lines' => [[
                    'stock_transfer_line_id' => $lineId,
                    'outcome' => 'write_off',
                    'quantity' => 4,
                    'stock_adjustment_reason_id' => $reasonId,
                ]],
            ])
            ->assertOk();

        $this->assertInventoryMatchesStock();
        $this->assertBalance(30, self::INVENTORY);
        $this->assertBalance(20, self::SHORTAGE);

        $this->tenant->run(function (): void {
            JournalEntry::query()->where('source_type', 'stock_transfer_closure')->delete();
            $this->assertSame(1, app(AccountingBackfillService::class)->run());
            $this->assertSame(0, app(AccountingBackfillService::class)->run());
        });

        $this->assertInventoryMatchesStock();
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

    public function test_income_statement_and_balance_sheet_follow_the_ledger(): void
    {
        $this->openingStock('10', '60');
        $this->postSalesInvoice(100);

        $income = $this->incomeStatement('?from=2026-09-01&to=2026-09-30');
        $this->assertEqualsWithDelta(100, $this->sectionAmount($income['sections'], 'net_sales'), 0.001);
        $this->assertEqualsWithDelta(60, $this->sectionAmount($income['sections'], 'cost_of_sales'), 0.001);
        $this->assertEqualsWithDelta(40, $this->sectionAmount($income['sections'], 'gross_profit'), 0.001);
        $this->assertEqualsWithDelta(40, $this->sectionAmount($income['sections'], 'net_result'), 0.001);

        $this->postManual([
            ['account_id' => $this->accountId('6263'), 'debit' => 10],
            ['account_id' => $this->accountId('5120'), 'credit' => 10],
        ]);

        $income = $this->incomeStatement('?from=2026-09-01&to=2026-09-30');
        $this->assertEqualsWithDelta(10, $this->sectionAmount($income['sections'], 'operating_expenses'), 0.001);
        $this->assertEqualsWithDelta(30, $this->sectionAmount($income['sections'], 'net_result'), 0.001);

        $sheet = $this->balanceSheet('?as_of=2026-09-30');
        $this->assertEqualsWithDelta(0, (float) $sheet['difference'], 0.001);
        $this->assertEqualsWithDelta(
            (float) $sheet['total_assets'],
            (float) $sheet['total_equity_and_liabilities'],
            0.001,
        );
        $this->assertEqualsWithDelta(
            30,
            $this->computedAmount($sheet['equity_and_liabilities']['sections'], 'current_year_result'),
            0.001,
        );

        $this->postManual([
            ['account_id' => $this->accountId('7019'), 'debit' => 10],
            ['account_id' => $this->accountId(self::CASH), 'credit' => 10],
        ]);
        $income = $this->incomeStatement('?from=2026-09-01&to=2026-09-30');
        $this->assertEqualsWithDelta(90, $this->sectionAmount($income['sections'], 'net_sales'), 0.001);
        $this->assertEqualsWithDelta(20, $this->sectionAmount($income['sections'], 'net_result'), 0.001);

        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 25],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 25],
        ], '2025-12-01');
        $sheet = $this->balanceSheet('?as_of=2026-09-30');
        $this->assertEqualsWithDelta(25, $this->computedAmount($sheet['equity_and_liabilities']['sections'], 'unclosed_prior_years_result'), 0.001);
        $this->assertEqualsWithDelta(20, $this->computedAmount($sheet['equity_and_liabilities']['sections'], 'current_year_result'), 0.001);

        $rootId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounts'), [
                'code' => '9',
                'name' => 'Off chart',
                'is_group' => true,
                'is_active' => true,
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounts'), [
                'code' => '91',
                'name' => 'Unclassified expense',
                'parent_id' => $rootId,
                'type' => 'expense',
                'normal_balance' => 'debit',
                'is_group' => false,
                'is_active' => true,
            ])
            ->assertCreated();
        $this->postManual([
            ['account_id' => $this->accountId('91'), 'debit' => 5],
            ['account_id' => $this->accountId(self::CASH), 'credit' => 5],
        ]);

        $income = $this->incomeStatement('?from=2026-09-01&to=2026-09-30');
        $unclassified = array_column($income['unclassified']['rows'], 'code');
        $this->assertContains('91', $unclassified);
        $this->assertEqualsWithDelta(15, $this->sectionAmount($income['sections'], 'net_result'), 0.001);

        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 8],
            ['account_id' => $this->accountId('2823'), 'credit' => 8],
        ]);
        $sheet = $this->balanceSheet('?as_of=2026-09-30');
        $this->assertEqualsWithDelta(0, (float) $sheet['difference'], 0.001);
        $this->assertEqualsWithDelta(15, $this->computedAmount($sheet['equity_and_liabilities']['sections'], 'current_year_result'), 0.001);
        $this->assertEqualsWithDelta(15, $this->sectionAmount($income['sections'], 'net_result'), 0.001);
        $this->assertEqualsWithDelta(-8, $this->sectionAmount($sheet['assets']['sections'], 'non_current_assets'), 0.001);
        $this->assertEqualsWithDelta(-8, $this->accountAmount($sheet['assets']['sections'], '2823'), 0.001);

        $this->asTenantRequest($this->token)
            ->get($this->tenantUrl('/accounting/income-statement/export?format=xlsx&from=2026-09-01&to=2026-09-30'))
            ->assertOk();
    }

    public function test_fiscal_year_start_changes_the_default_period_and_the_result_split(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));
        $this->tenant->run(function (): void {
            CompanySetting::singleton()->update(['fiscal_year_start_month' => 7]);
            app(CompanySettingService::class)->forgetCache();
        });

        $income = $this->incomeStatement('');
        $this->assertSame('2026-07-01', $income['period']['from']);
        $this->assertSame('2026-10-14', $income['period']['to']);

        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 100],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 100],
        ], '2026-08-01');
        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 40],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 40],
        ], '2026-06-15');
        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 7],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 7],
        ], '2025-08-15');

        $sheet = $this->balanceSheet('?as_of=2026-10-14');
        $this->assertEqualsWithDelta(100, $this->computedAmount($sheet['equity_and_liabilities']['sections'], 'current_year_result'), 0.001);
        $this->assertEqualsWithDelta(47, $this->computedAmount($sheet['equity_and_liabilities']['sections'], 'unclosed_prior_years_result'), 0.001);
        $this->assertEqualsWithDelta(0, (float) $sheet['difference'], 0.001);

        $compared = $this->incomeStatement('?from=2026-08-01&to=2026-08-31&compare=previous_year');
        $this->assertSame('2025-08-01', $compared['compare_period']['from']);
        $this->assertSame('2025-08-31', $compared['compare_period']['to']);
        $this->assertEqualsWithDelta(100, $this->sectionAmount($compared['sections'], 'net_sales'), 0.001);
        $this->assertEqualsWithDelta(7, (float) $this->reportSection($compared['sections'], 'net_sales')['compare_amount'], 0.001);
    }

    public function test_branch_filter_keeps_only_that_branch(): void
    {
        $kept = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 30],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 30],
        ]);
        $dropped = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 10],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 10],
        ]);

        $branchId = $this->tenant->run(function () use ($kept, $dropped): int {
            $branchId = (int) Branch::query()->create([
                'name' => 'North',
                'shortcut_name' => 'NTH',
                'is_active' => true,
                'is_default' => false,
            ])->id;
            JournalEntry::query()->whereKey($kept)->update(['branch_id' => $branchId]);
            JournalEntry::query()->whereKey($dropped)->update(['branch_id' => null]);

            return $branchId;
        });

        $income = $this->incomeStatement('?from=2026-09-01&to=2026-09-30&branch_id='.$branchId);
        $this->assertEqualsWithDelta(30, $this->sectionAmount($income['sections'], 'net_sales'), 0.001);

        $trial = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/trial-balance?from=2026-09-01&to=2026-09-30&branch_id='.$branchId))
            ->assertOk()
            ->json('data');
        $this->assertSame($branchId, (int) $trial['branch_id']);
        $cash = collect($trial['rows'])->firstWhere('code', self::CASH);
        $this->assertEqualsWithDelta(30, (float) ($cash['period_debit'] ?? 0), 0.001);
    }

    public function test_a_user_limited_to_one_branch_cannot_see_the_others(): void
    {
        $kept = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 30],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 30],
        ]);
        $hidden = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 10],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 10],
        ]);

        [$token, $homeId, $otherId] = $this->tenant->run(function () use ($kept, $hidden): array {
            $home = Branch::query()->where('is_default', true)->firstOrFail();
            $other = Branch::query()->create([
                'name' => 'East',
                'shortcut_name' => 'EST',
                'is_active' => true,
                'is_default' => false,
            ]);
            JournalEntry::query()->whereKey($kept)->update(['branch_id' => $home->id]);
            JournalEntry::query()->whereKey($hidden)->update(['branch_id' => $other->id]);

            $roleId = (int) Role::query()->where('name', 'Admin')->value('id');
            $user = User::factory()->create([
                'name' => 'Branch clerk',
                'email' => 'clerk@example.com',
            ]);
            $user->branches()->attach($home->id, ['role_id' => $roleId]);

            return [$user->createToken('clerk')->plainTextToken, (int) $home->id, (int) $other->id];
        });

        // Earlier requests in this test leave the owner on the auth guard.
        $this->app['auth']->forgetGuards();

        $report = $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('/accounting/income-statement?from=2026-09-01&to=2026-09-30'))
            ->assertOk()
            ->json('data');

        $this->assertSame($homeId, (int) $report['branch_id']);
        $this->assertEqualsWithDelta(30, $this->sectionAmount($report['sections'], 'net_sales'), 0.001);

        $trial = $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('/accounting/trial-balance?from=2026-09-01&to=2026-09-30'))
            ->assertOk()
            ->json('data');
        $this->assertSame($homeId, (int) $trial['branch_id']);
        $cash = collect($trial['rows'])->firstWhere('code', self::CASH);
        $this->assertEqualsWithDelta(30, (float) ($cash['period_debit'] ?? 0), 0.001);

        $ledger = $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('/accounting/account-ledger?account_id='.$this->accountId(self::CASH).'&from=2026-09-01&to=2026-09-30'))
            ->assertOk()
            ->json('data');
        $this->assertSame($homeId, (int) $ledger['branch_id']);
        $this->assertEqualsWithDelta(30, (float) $ledger['period_debit'], 0.001);
        $this->assertCount(1, $ledger['lines']);

        $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('/accounting/income-statement?from=2026-09-01&to=2026-09-30&branch_id='.$otherId))
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
        $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('/accounting/trial-balance?from=2026-09-01&to=2026-09-30&branch_id='.$otherId))
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
        $this->asTenantRequest($token)
            ->getJson($this->tenantUrl('/accounting/account-ledger?account_id='.$this->accountId(self::CASH).'&branch_id='.$otherId))
            ->assertForbidden()
            ->assertJsonPath('code', 'BRANCH_ACCESS_DENIED');
    }

    public function test_balance_sheet_checks_match_stock_and_party_ledgers(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));

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

        $this->postSalesInvoice(100, null, ['invoice_date' => '2026-09-02']);

        $countId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/stock-counts'), [
                'warehouse_id' => $this->catalog['warehouse_id'],
                'count_date' => '2026-09-03',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'theoretical_quantity' => 9,
                    'counted_quantity' => 7,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/stock-counts/{$countId}/post"))
            ->assertOk();

        $destinationId = $this->tenant->run(function (): int {
            return (int) Warehouse::query()->create([
                'name' => 'Shop WH',
                'shortcut_name' => 'SWH',
                'type' => WarehouseType::Central,
                'manager_id' => $this->tenantUser->id,
                'is_active' => true,
                'is_default' => false,
                'is_default_sales' => false,
                'is_default_production' => false,
                'is_default_purchase' => false,
                'is_default_storage' => true,
            ])->id;
        });
        $reasonId = $this->tenant->run(fn (): int => (int) StockAdjustmentReason::query()->create([
            'code' => 'WO',
            'name' => 'Write off',
            'direction' => 'decrease',
            'is_active' => true,
        ])->id);
        $transfer = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/transfers'), [
                'from_warehouse_id' => $this->catalog['warehouse_id'],
                'to_warehouse_id' => $destinationId,
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 4,
                ]],
            ])
            ->assertCreated()
            ->json('data');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/transfers/{$transfer['id']}/dispatch"))
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/transfers/{$transfer['id']}/receive"), [
                'lines' => [['stock_transfer_line_id' => $transfer['lines'][0]['id'], 'quantity' => 2]],
            ])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/transfers/{$transfer['id']}/close-open"), [
                'lines' => [[
                    'stock_transfer_line_id' => $transfer['lines'][0]['id'],
                    'outcome' => 'write_off',
                    'quantity' => 2,
                    'stock_adjustment_reason_id' => $reasonId,
                ]],
            ])
            ->assertOk();

        $purchaseId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-04',
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 40,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$purchaseId}/post"))
            ->assertOk();

        $sheet = $this->balanceSheet('?as_of=2026-10-14');
        $this->assertEqualsWithDelta(0, (float) $sheet['difference'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $sheet['checks']['inventory']['difference'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $sheet['checks']['receivables']['difference'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $sheet['checks']['payables']['difference'], 0.001);
        $this->assertNotNull($sheet['checks']['inventory']['stock']);
    }

    public function test_vat_report_settlement_and_reversal(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));

        $items = $this->tenant->run(function (): array {
            $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
            $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
            $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();
            $zero = VatGroup::query()->create([
                'abrv' => 'VAT0',
                'name' => 'VAT 0',
                'percentage' => 0,
                'is_default' => false,
                'is_active' => true,
            ]);
            $five = VatGroup::query()->create([
                'abrv' => 'VAT5',
                'name' => 'VAT 5',
                'percentage' => 5,
                'is_default' => false,
                'is_active' => true,
            ]);

            return [
                'zero' => (string) $this->makeItem('Zero rated', 'SVC-0', $serviceType->id, $unitGroup->id, $uom->id, $zero->id, false)->id,
                'five' => (string) $this->makeItem('Five rated', 'SVC-5', $serviceType->id, $unitGroup->id, $uom->id, $five->id, false)->id,
            ];
        });

        $standard = $this->postSalesInvoice(100, $this->catalog['service_item_id']);
        $this->postSalesInvoice(200, $items['five']);
        $this->postSalesInvoice(50, $items['zero']);

        $purchaseId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 40,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$purchaseId}/post"))
            ->assertOk();

        $september = $this->vatReport('?from=2026-09-01&to=2026-09-30');
        $this->assertEqualsWithDelta(100, (float) $this->vatRate($september['output'], 11)['taxable_amount'], 0.001);
        $this->assertEqualsWithDelta(11, (float) $this->vatRate($september['output'], 11)['vat'], 0.001);
        $this->assertEqualsWithDelta(200, (float) $this->vatRate($september['output'], 5)['taxable_amount'], 0.001);
        $this->assertEqualsWithDelta(10, (float) $this->vatRate($september['output'], 5)['vat'], 0.001);
        $this->assertEqualsWithDelta(50, (float) $this->vatRate($september['output'], 0)['taxable_amount'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $this->vatRate($september['output'], 0)['vat'], 0.001);
        $this->assertEqualsWithDelta(4.4, (float) $this->vatRate($september['input'], 11)['vat'], 0.001);
        $this->assertEqualsWithDelta(16.6, (float) $september['net'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $september['ledger_check']['output_difference'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $september['ledger_check']['input_difference'], 0.001);

        $settlement = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/vat-settlements'), [
                'period_to' => '2026-09-30',
                'entry_date' => '2026-09-30',
            ])
            ->assertOk();
        $firstId = (string) $settlement->json('data.id');
        $this->assertBalance(0, self::VAT_OUTPUT);
        $this->assertBalance(0, self::VAT_INPUT);
        $this->assertBalance(-16.6, '44251');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/vat-settlements'), [
                'period_to' => '2026-09-30',
                'entry_date' => '2026-09-30',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'VAT_SETTLEMENT_OVERLAP');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$standard}/reverse"))
            ->assertOk();

        $october = $this->vatReport('?from=2026-10-01&to=2026-10-14');
        $this->assertEqualsWithDelta(-11, (float) $this->vatRate($october['output'], 11)['vat'], 0.001);
        $this->assertEqualsWithDelta(0, (float) $october['ledger_check']['output_difference'], 0.001);

        $second = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/vat-settlements'), [
                'period_to' => '2026-10-14',
                'entry_date' => '2026-10-14',
            ])
            ->assertOk();
        $secondId = (string) $second->json('data.id');
        $this->assertBalance(0, self::VAT_OUTPUT);
        $this->assertBalance(11, '44252');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/accounting/vat-settlements/{$firstId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'VAT_SETTLEMENT_NOT_LATEST');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/accounting/vat-settlements/{$secondId}/reverse"))
            ->assertOk();
        $this->assertBalance(11, self::VAT_OUTPUT);
        $this->assertBalance(0, '44252');
    }

    public function test_input_vat_above_output_is_carried_forward(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-14 12:00:00', 'UTC'));

        $purchaseId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$purchaseId}/post"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/vat-settlements'), [
                'period_to' => '2026-09-30',
                'entry_date' => '2026-09-30',
            ])
            ->assertOk();

        $this->assertBalance(0, self::VAT_INPUT);
        $this->assertBalance(0, self::VAT_OUTPUT);
        $this->assertBalance(11, '44252');
    }

    public function test_reports_require_the_accounting_module_and_view_permission(): void
    {
        $this->tenant->run(function (): void {
            $permissionId = Permission::query()->where('resource_key', 'accounting_reports')->value('id');
            RolePermission::query()->where('permission_id', $permissionId)->update(['can_view' => false]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/income-statement'))
            ->assertForbidden();

        $this->setAccountingModule(false);
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/income-statement'))
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
    }

    public function test_period_lock_blocks_posting_and_keeps_stock_and_the_customer_ledger(): void
    {
        Carbon::setTestNow('2026-10-16');
        $this->openingStock('10', '60');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/customers/'.$this->catalog['customer_id']), [
                'currency_balances' => [[
                    'currency_id' => $this->catalog['usd_id'],
                    'opening_balance' => 50,
                    'opening_date' => '2026-09-01',
                ]],
            ])
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-09-30'])
            ->assertOk()
            ->assertJsonPath('data.books_closed_through', '2026-09-30')
            ->assertJsonPath('data.open_year.starts_on', '2026-01-01')
            ->assertJsonPath('data.open_year.ends_on', '2026-12-31');

        $before = $this->tenant->run(fn (): array => [
            'stock' => (float) StockBalance::query()->where('item_id', $this->catalog['stock_item_id'])->sum('quantity'),
            'ledger' => CustomerLedgerEntry::query()->where('customer_id', $this->catalog['customer_id'])->count(),
        ]);

        $invoiceId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-30',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_LOCKED');

        $after = $this->tenant->run(fn (): array => [
            'stock' => (float) StockBalance::query()->where('item_id', $this->catalog['stock_item_id'])->sum('quantity'),
            'ledger' => CustomerLedgerEntry::query()->where('customer_id', $this->catalog['customer_id'])->count(),
            'journals' => JournalEntry::query()->where('source_id', $invoiceId)->count(),
        ]);
        $this->assertEqualsWithDelta($before['stock'], $after['stock'], 0.0001);
        $this->assertSame($before['ledger'], $after['ledger']);
        $this->assertSame(0, $after['journals']);

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoiceId}"), ['invoice_date' => '2026-10-01'])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/reverse"))
            ->assertOk();
        $reversalDate = $this->tenant->run(fn (): string => JournalEntry::query()
            ->where('source_id', $invoiceId)
            ->where('source_event', 'reverse')
            ->value('entry_date')
            ->toDateString());
        $this->assertSame('2026-10-16', $reversalDate);

        $draftId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/journal-entries'), [
                'entry_date' => '2026-09-30',
                'description' => 'Closed period draft',
                'lines' => [
                    ['account_id' => $this->accountId(self::CASH), 'debit' => 10],
                    ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 10],
                ],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$draftId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_LOCKED');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/customers/'.$this->catalog['customer_id']), [
                'currency_balances' => [[
                    'currency_id' => $this->catalog['usd_id'],
                    'opening_balance' => 80,
                    'opening_date' => '2026-09-01',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_LOCKED');
        $opening = $this->tenant->run(fn (): float => (float) CustomerBalance::query()
            ->where('customer_id', $this->catalog['customer_id'])
            ->where('currency_id', $this->catalog['usd_id'])
            ->value('opening_balance'));
        $this->assertEqualsWithDelta(50, $opening, 0.001);
    }

    public function test_reopening_the_lock_needs_permission_and_cannot_uncover_a_closed_year(): void
    {
        Carbon::setTestNow('2027-02-01');
        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 10],
            ['account_id' => $this->accountId(self::CAPITAL), 'credit' => 10],
        ], '2026-03-01');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2026-12-31'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'YEAR_NOT_LOCKED');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-12-31'])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2026-12-31'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'YEAR_CLOSE_EMPTY');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-05-31'])
            ->assertOk();
        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 25],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 25],
        ], '2026-06-01');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-12-31'])
            ->assertOk();

        $this->tenant->run(function (): void {
            $permissionId = Permission::query()->where('resource_key', 'accounting_periods')->value('id');
            RolePermission::query()->where('permission_id', $permissionId)->update(['can_reopen' => false]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-06-30'])
            ->assertForbidden();

        $this->tenant->run(function (): void {
            $permissionId = Permission::query()->where('resource_key', 'accounting_periods')->value('id');
            RolePermission::query()->where('permission_id', $permissionId)->update(['can_reopen' => true]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-06-30'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_REOPEN_CLOSED_YEAR');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => null])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_REOPEN_CLOSED_YEAR');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2027-01-31'])
            ->assertOk()
            ->assertJsonPath('data.books_closed_through', '2027-01-31');
    }

    public function test_year_end_close_moves_profit_or_loss_and_only_the_latest_close_reverses(): void
    {
        Carbon::setTestNow('2027-02-01');
        $this->postManual([
            ['account_id' => $this->accountId(self::COGS), 'debit' => 40],
            ['account_id' => $this->accountId(self::CASH), 'credit' => 40],
        ], '2025-06-01');
        $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 100],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 100],
        ], '2026-06-01');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-12-31'])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2026-12-31'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'YEAR_CLOSE_OUT_OF_ORDER');

        $first = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2025-12-31'])
            ->assertOk()
            ->json('data');
        $this->assertSame('JE-', substr((string) $first['closing_entry_number'], 0, 3));
        $this->assertBalance(0, self::COGS);
        $this->assertBalance(40, '1250');

        $lossSheet = $this->balanceSheet('?as_of=2025-12-31');
        $this->assertEqualsWithDelta(0, (float) $lossSheet['difference'], 0.001);
        $this->assertEqualsWithDelta(0, $this->computedAmount($lossSheet['equity_and_liabilities']['sections'], 'current_year_result'), 0.001);
        $this->assertEqualsWithDelta(0, $this->sectionAmount($this->incomeStatement('?from=2025-01-01&to=2025-12-31')['sections'], 'net_result'), 0.001);

        $second = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2026-12-31'])
            ->assertOk()
            ->json('data');
        $this->assertBalance(0, self::REVENUE);
        $this->assertBalance(-100, '1210');

        $profitSheet = $this->balanceSheet('?as_of=2026-12-31');
        $this->assertEqualsWithDelta(0, (float) $profitSheet['difference'], 0.001);
        $this->assertEqualsWithDelta(0, $this->computedAmount($profitSheet['equity_and_liabilities']['sections'], 'current_year_result'), 0.001);
        $this->assertEqualsWithDelta(0, $this->sectionAmount($this->incomeStatement('?from=2026-01-01&to=2026-12-31')['sections'], 'net_result'), 0.001);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/'.$first['id'].'/reverse'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'YEAR_CLOSE_NOT_LATEST');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/'.$second['id'].'/reverse'))
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/period'))
            ->assertOk()
            ->assertJsonPath('data.books_closed_through', '2026-12-31');

        $reversalDate = $this->tenant->run(fn (): string => JournalEntry::query()
            ->where('source_type', 'year_end_close')
            ->where('source_event', 'reverse')
            ->orderByDesc('id')
            ->value('entry_date')
            ->toDateString());
        $this->assertSame('2027-02-01', $reversalDate);
        $this->assertEqualsWithDelta(
            100,
            $this->sectionAmount($this->incomeStatement('?from=2027-01-01&to=2027-02-01')['sections'], 'net_result'),
            0.001,
        );

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/company-settings'), ['fiscal_year_start_month' => 7])
            ->assertStatus(422)
            ->assertJsonPath('code', 'FISCAL_YEAR_START_LOCKED');
    }

    public function test_tenant_without_accounting_still_posts_an_invoice_dated_in_the_past(): void
    {
        $this->setAccountingModule(false);
        $id = $this->postSalesInvoice(20, $this->catalog['service_item_id'], ['invoice_date' => '2020-01-15']);

        $this->tenant->run(function () use ($id): void {
            $this->assertSame(0, JournalEntry::query()->where('source_id', $id)->count());
        });
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

    private function assertInventoryMatchesStock(): void
    {
        $this->tenant->run(function (): void {
            $stock = (float) StockBalance::query()->sum('inventory_value');
            $id = (int) Account::query()->where('code', self::INVENTORY)->value('id');
            $sum = app(AccountBalanceService::class)->totals()[$id] ?? ['debit' => '0', 'credit' => '0'];
            $ledger = (float) $sum['debit'] - (float) $sum['credit'];

            $this->assertEqualsWithDelta($stock, $ledger, 0.0001);
        });
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

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function postManual(array $lines, string $date = '2026-09-01'): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/journal-entries'), [
                'entry_date' => $date,
                'description' => 'Manual',
                'lines' => $lines,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/journal-entries/{$id}/post"))
            ->assertOk();

        return (string) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function incomeStatement(string $query): array
    {
        return $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/income-statement'.$query))
            ->assertOk()
            ->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function balanceSheet(string $query): array
    {
        return $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/balance-sheet'.$query))
            ->assertOk()
            ->json('data');
    }

    /**
     * @return array<string, mixed>
     */
    private function vatReport(string $query): array
    {
        return $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/vat-report'.$query))
            ->assertOk()
            ->json('data');
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function reportSection(array $sections, string $key): array
    {
        foreach ($sections as $section) {
            if (($section['key'] ?? null) === $key) {
                return $section;
            }
        }

        $this->fail("Missing section {$key}");
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function sectionAmount(array $sections, string $key): float
    {
        return (float) $this->reportSection($sections, $key)['amount'];
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function computedAmount(array $sections, string $key): float
    {
        foreach ($sections as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                if (($row['key'] ?? null) === $key) {
                    return (float) $row['amount'];
                }
            }
        }

        $this->fail("Missing computed row {$key}");
    }

    /**
     * @param  list<array<string, mixed>>  $sections
     */
    private function accountAmount(array $sections, string $code): float
    {
        foreach ($sections as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                if (($row['code'] ?? null) === $code) {
                    return (float) $row['amount'];
                }
            }
        }

        $this->fail("Missing account {$code}");
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function vatRate(array $groups, float $rate): array
    {
        foreach ($groups as $group) {
            if (abs((float) ($group['tax_rate'] ?? 0) - $rate) < 0.001) {
                return $group;
            }
        }

        $this->fail("Missing VAT rate {$rate}");
    }
}
