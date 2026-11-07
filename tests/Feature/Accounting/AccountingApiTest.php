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
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Customer\Services\WalkInCustomerService;
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
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use App\Services\PermissionService;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfOptions;
use App\Support\DocumentPdf\DocumentPdfRenderer;
use App\Support\DocumentPdf\DocumentPdfSupport;
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
        // EUR cash goes to the EUR cash box (ledger 2812).
        $this->assertBalance(138.75, '2812');
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
                'opening_documents' => [[
                    'currency_id' => $this->catalog['usd_id'],
                    'document_date' => '2026-09-01',
                    'amount' => 50,
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

        $openingId = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$this->catalog['customer_id'].'/opening-documents'))
            ->assertOk()
            ->json('data.0.id');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/customers/'.$this->catalog['customer_id']), [
                'opening_documents' => [[
                    'id' => $openingId,
                    'currency_id' => $this->catalog['usd_id'],
                    'document_date' => '2026-09-01',
                    'amount' => 80,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PERIOD_LOCKED');
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$this->catalog['customer_id'].'/opening-documents'))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '50.0000');
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

    public function test_year_end_close_posts_one_entry_per_branch_and_reverses_them_all(): void
    {
        Carbon::setTestNow('2027-02-01');
        $home = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 100],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 100],
        ], '2026-03-01');
        $north = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 30],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 30],
        ], '2026-04-01');
        $noBranch = $this->postManual([
            ['account_id' => $this->accountId(self::CASH), 'debit' => 5],
            ['account_id' => $this->accountId(self::REVENUE), 'credit' => 5],
        ], '2026-05-01');

        [$homeId, $northId] = $this->tenant->run(function () use ($home, $north, $noBranch): array {
            $homeId = (int) Branch::query()->where('is_default', true)->value('id');
            $northId = (int) Branch::query()->create([
                'name' => 'North', 'shortcut_name' => 'NTH', 'is_active' => true, 'is_default' => false,
            ])->id;
            JournalEntry::query()->whereKey($home)->update(['branch_id' => $homeId]);
            JournalEntry::query()->whereKey($north)->update(['branch_id' => $northId]);
            JournalEntry::query()->whereKey($noBranch)->update(['branch_id' => null]);

            return [$homeId, $northId];
        });

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/accounting/period'), ['books_closed_through' => '2026-12-31'])
            ->assertOk();
        $year = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/close'), ['ends_on' => '2026-12-31'])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->json('data');
        $this->assertEqualsWithDelta(135, (float) $year['result'], 0.001);

        // One closing entry per branch, plus one without a branch for the old entry that has none.
        $closings = $this->tenant->run(fn (): array => JournalEntry::query()
            ->where('source_type', 'year_end_close')
            ->where('source_event', 'post')
            ->pluck('branch_id')
            ->map(fn ($id): ?int => $id === null ? null : (int) $id)
            ->all());
        $this->assertEqualsCanonicalizing([$homeId, $northId, null], $closings);

        $this->assertBalance(0, self::REVENUE);
        $this->assertEqualsWithDelta(0, $this->branchBalance(self::REVENUE, $homeId), 0.001);
        $this->assertEqualsWithDelta(0, $this->branchBalance(self::REVENUE, $northId), 0.001);
        $this->assertEqualsWithDelta(-100, $this->branchBalance('1210', $homeId), 0.001);
        $this->assertEqualsWithDelta(-30, $this->branchBalance('1210', $northId), 0.001);

        // Reversing the year reverses every branch's closing entry.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/fiscal-years/'.$year['id'].'/reverse'))
            ->assertOk()
            ->assertJsonPath('data.status', 'open');
        $this->assertBalance(-135, self::REVENUE);
        $this->assertBalance(0, '1210');
        $this->assertSame(3, $this->tenant->run(fn (): int => JournalEntry::query()
            ->where('source_type', 'year_end_close')
            ->where('source_event', 'reverse')
            ->count()));

    }

    public function test_customer_opening_documents_post_to_the_books_and_can_be_paid_and_credited(): void
    {
        Carbon::setTestNow('2026-10-16');
        $customerUrl = '/customers/'.$this->catalog['customer_id'];
        $this->saveCustomerOpenings([
            ['original_number' => 'OLD-1', 'document_date' => '2026-08-01', 'due_on' => '2026-08-15', 'amount' => 2000],
            ['original_number' => 'OLD-2', 'document_date' => '2026-09-01', 'due_on' => '2026-10-31', 'amount' => 1000],
            ['original_number' => 'ADV-1', 'document_date' => '2026-09-01', 'amount' => -300],
        ]);

        $rows = $this->customerOpenings();
        $this->assertCount(3, $rows);
        $byOriginal = collect($rows)->keyBy('original_number');
        $this->assertSame('invoice', $byOriginal['OLD-1']['kind']);
        $this->assertStringStartsWith('OSI', (string) $byOriginal['OLD-1']['number']);
        $this->assertSame('credit', $byOriginal['ADV-1']['kind']);
        $this->assertStringStartsWith('OCN', (string) $byOriginal['ADV-1']['number']);
        $this->assertSame('-300.0000', $byOriginal['ADV-1']['amount']);

        // Books: receivable against opening balance equity; no revenue.
        $this->assertBalance(2700, self::RECEIVABLE);
        $this->assertBalance(-2700, '1201');
        $this->assertBalance(0, self::REVENUE);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl($customerUrl.'/balance'))
            ->assertOk()
            ->assertJsonPath('data.currencies.0.opening_balance', '2700.0000')
            ->assertJsonPath('data.currencies.0.balance', fn ($v): bool => (float) $v === 2700.0);

        // Aging sees the opening invoices by due date; period sales do not count them.
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/customer-aging?as_of=2026-10-16&customer_id='.$this->catalog['customer_id']))
            ->assertOk()
            ->assertJsonPath('data.rows.0.current', '1000.0000')
            ->assertJsonPath('data.rows.0.61_90', '2000.0000');
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/reports/sales-by-period?from=2026-08-01&to=2026-10-31'))
            ->assertOk()
            ->assertJsonPath('data.totals.document_count', 0);

        // A receipt pays the old invoice; it is then locked except for its old number and due date.
        $old1 = (string) $byOriginal['OLD-1']['id'];
        $open = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customer-receipts/open-invoices?customer_id='.$this->catalog['customer_id'].'&currency_id='.$this->catalog['usd_id']))
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($open)->firstWhere('id', $old1)['is_opening']);
        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-10-16',
                'amount' => 2000,
                'allocations' => [['sales_invoice_id' => $old1, 'amount' => 2000]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))->assertOk();
        $this->assertBalance(700, self::RECEIVABLE);

        $rows = collect($this->customerOpenings())->keyBy('original_number');
        $this->assertTrue($rows['OLD-1']['locked']);
        $this->assertSame('0.0000', $rows['OLD-1']['open_amount']);
        $payload = $this->openingPayload($rows->all());
        $payload[0]['amount'] = 2500;
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl($customerUrl), ['opening_documents' => $payload])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_DOCUMENT_IN_USE');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl($customerUrl), ['opening_documents' => array_slice($this->openingPayload($rows->all()), 1)])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_DOCUMENT_IN_USE');
        $payload = $this->openingPayload($rows->all());
        $payload[0]['original_number'] = 'OLD-1A';
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl($customerUrl), ['opening_documents' => $payload])
            ->assertOk();
        $this->assertSame('OLD-1A', collect($this->customerOpenings())->firstWhere('id', $old1)['original_number']);

        // The opening credit is applied to the other opening invoice.
        $credit = (string) $rows['ADV-1']['id'];
        $old2 = (string) $rows['OLD-2']['id'];
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$credit}/apply"), [
                'allocations' => [['sales_invoice_id' => $old2, 'amount' => 300]],
            ])
            ->assertOk();
        $rows = collect($this->customerOpenings())->keyBy('id');
        $this->assertSame('700.0000', $rows[$old2]['open_amount']);
        $this->assertTrue($rows[$credit]['locked']);
        $this->assertBalance(700, self::RECEIVABLE);

        // Opening documents are not reversed, printed or returned like normal documents.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$old2}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_DOCUMENT_MANAGED_BY_PARTY');
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/sales-credit-notes/invoice-lines?sales_invoice_id='.$old2))
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_INVOICE_NO_RETURN');
    }

    public function test_unused_opening_documents_can_be_edited_and_removed(): void
    {
        Carbon::setTestNow('2026-10-16');
        $customerUrl = '/customers/'.$this->catalog['customer_id'];
        $this->saveCustomerOpenings([
            ['document_date' => '2026-09-01', 'amount' => 1000],
        ]);
        $rows = $this->customerOpenings();
        $id = (string) $rows[0]['id'];
        $number = $rows[0]['number'];

        $payload = $this->openingPayload($rows);
        $payload[0]['amount'] = 1200;
        $this->asTenantRequest($this->token)->putJson($this->tenantUrl($customerUrl), ['opening_documents' => $payload])->assertOk();
        $rows = $this->customerOpenings();
        $this->assertCount(1, $rows);
        $this->assertSame('1200.0000', $rows[0]['amount']);
        $this->assertNotSame($id, $rows[0]['id']);
        $this->assertNotSame($number, $rows[0]['number']);
        $this->assertBalance(1200, self::RECEIVABLE);

        // The sign flips: the invoice becomes an opening credit.
        $payload = $this->openingPayload($rows);
        $payload[0]['amount'] = -50;
        $this->asTenantRequest($this->token)->putJson($this->tenantUrl($customerUrl), ['opening_documents' => $payload])->assertOk();
        $this->assertSame('credit', $this->customerOpenings()[0]['kind']);
        $this->assertBalance(-50, self::RECEIVABLE);

        $this->asTenantRequest($this->token)->putJson($this->tenantUrl($customerUrl), ['opening_documents' => []])->assertOk();
        $this->assertSame([], $this->customerOpenings());
        $this->assertBalance(0, self::RECEIVABLE);
        $this->assertBalance(0, '1201');
        $this->tenant->run(function (): void {
            $this->assertSame(0, CustomerLedgerEntry::query()->where('customer_id', $this->catalog['customer_id'])->count());
            $this->assertSame(0, JournalEntry::query()->whereIn('source_type', ['sales_invoice', 'sales_credit_note'])->count());
        });

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl($customerUrl), ['opening_documents' => [
                ['currency_id' => $this->catalog['usd_id'], 'document_date' => '2026-09-01', 'due_on' => '2026-08-01', 'amount' => 10],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_DOCUMENT_DUE_DATE_INVALID');
    }

    public function test_supplier_opening_documents_post_to_payable_and_can_be_paid(): void
    {
        Carbon::setTestNow('2026-10-16');
        $supplierUrl = '/suppliers/'.$this->catalog['supplier_id'];
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl($supplierUrl), ['opening_documents' => [
                ['currency_id' => $this->catalog['usd_id'], 'original_number' => 'BILL-9', 'document_date' => '2026-09-01', 'amount' => 500],
                ['currency_id' => $this->catalog['usd_id'], 'document_date' => '2026-09-01', 'amount' => -100],
            ]])
            ->assertOk();

        $rows = $this->asTenantRequest($this->token)->getJson($this->tenantUrl($supplierUrl.'/opening-documents'))->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertBalance(-400, self::PAYABLE);
        $this->assertBalance(400, '1201');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl($supplierUrl.'/balance'))
            ->assertOk()
            ->assertJsonPath('data.currencies.0.opening_balance', '400.0000')
            ->assertJsonPath('data.currencies.0.balance', fn ($v): bool => (float) $v === 400.0);

        $bill = (string) collect($rows)->firstWhere('original_number', 'BILL-9')['id'];
        $open = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/supplier-payments/open-invoices?supplier_id='.$this->catalog['supplier_id'].'&currency_id='.$this->catalog['usd_id']))
            ->assertOk()
            ->json('data');
        $this->assertSame([$bill], collect($open)->pluck('id')->all());
        $paymentId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/supplier-payments'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-10-16',
                'amount' => 500,
                'allocations' => [['purchase_invoice_id' => $bill, 'amount' => 500]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/supplier-payments/{$paymentId}/post"))->assertOk();
        $this->assertBalance(100, self::PAYABLE);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$bill}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_DOCUMENT_MANAGED_BY_PARTY');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function saveCustomerOpenings(array $rows): void
    {
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/customers/'.$this->catalog['customer_id']), [
                'opening_documents' => array_map(fn (array $row): array => $row + ['currency_id' => $this->catalog['usd_id']], $rows),
            ])
            ->assertOk();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customerOpenings(): array
    {
        return $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/customers/'.$this->catalog['customer_id'].'/opening-documents'))
            ->assertOk()
            ->json('data');
    }

    /**
     * Rows from the opening-documents endpoint, back as a save payload.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function openingPayload(array $rows): array
    {
        return array_values(array_map(fn (array $row): array => [
            'id' => $row['id'],
            'currency_id' => $row['currency_id'],
            'original_number' => $row['original_number'],
            'document_date' => $row['document_date'],
            'due_on' => $row['due_on'],
            'amount' => $row['amount'],
        ], $rows));
    }

    public function test_bill_discount_lowers_each_line_and_the_revenue(): void
    {
        $id = $this->postSalesInvoice(200, $this->catalog['service_item_id'], [
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]);

        $invoice = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertOk()
            ->assertJsonPath('data.header_discount_type', 'percent')
            ->assertJsonPath('data.discount_total', '20.0000')
            ->assertJsonPath('data.lines.0.header_discount_amount', '20.0000')
            ->json('data');

        $net = (float) $invoice['grand_total'] - (float) $invoice['tax_total'];
        $this->assertEqualsWithDelta(180, $net, 0.001);
        $this->assertBalance(-180, self::REVENUE);
        $this->assertBalance(0, '7191');
    }

    public function test_discount_above_the_role_limit_needs_a_manager_login(): void
    {
        $token = $this->cashierToken(10);

        $small = $this->draftInvoice(100, ['header_discount_type' => 'percent', 'header_discount_value' => 8], $token);
        $this->asTenantRequest($token)->postJson($this->tenantUrl("/sales-invoices/{$small}/post"))->assertOk();

        $big = $this->draftInvoice(100, ['header_discount_type' => 'percent', 'header_discount_value' => 15], $token);
        $this->asTenantRequest($token)
            ->postJson($this->tenantUrl("/sales-invoices/{$big}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DISCOUNT_APPROVAL_REQUIRED');
        $this->asTenantRequest($token)
            ->postJson($this->tenantUrl("/sales-invoices/{$big}/post"), ['discount_approval' => ['email' => 'owner@attach-test.local', 'password' => 'wrong']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DISCOUNT_APPROVAL_INVALID');
        $this->asTenantRequest($token)
            ->postJson($this->tenantUrl("/sales-invoices/{$big}/post"), ['discount_approval' => ['email' => 'owner@attach-test.local', 'password' => 'password']])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.discount_approved_by', (string) $this->tenantUser->id);
    }

    public function test_split_payment_at_sale_posts_receipts_to_each_cash_and_bank_account(): void
    {
        [$cash, $bank, $card] = $this->tillSetup();
        $invoice = $this->draftInvoice(100);
        $grand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoice}"))->json('data.grand_total');
        $onCard = round($grand - 60, 2);

        $payments = $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoice}/payments"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'amount' => 60, 'tendered' => 100],
                ['payment_method_id' => $card, 'cash_bank_account_id' => $bank, 'amount' => $onCard, 'reference' => 'TX-1'],
            ]])
            ->assertOk()
            ->json('data.payments');
        $this->assertSame($cash, $payments[0]['cash_bank_account_id']);
        $this->assertSame('40.0000', $payments[0]['change_due']);

        $posted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoice}/post"))
            ->assertOk()
            ->assertJsonPath('data.net_to_pay', '0.0000')
            ->json('data');
        $this->assertNotNull($posted['payments'][0]['receipt_number']);
        $this->assertBalance(0, self::RECEIVABLE);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '60.0000');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$bank}"))->assertJsonPath('data.balance', fn ($v): bool => abs((float) $v - $onCard) < 0.001);

        // The receipts belong to the invoice: reversed with it, never alone.
        $receiptId = (string) $posted['payments'][0]['customer_receipt_id'];
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CUSTOMER_RECEIPT_MANAGED_BY_INVOICE');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '0.0000');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/customer-receipts/{$receiptId}"))->assertJsonPath('data.status', 'reversed');
        $this->assertBalance(0, self::RECEIVABLE);
    }

    public function test_payments_at_sale_rules(): void
    {
        [, $bank] = $this->tillSetup();

        // Only the unpaid rest counts against the credit limit.
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/customers/'.$this->catalog['customer_id']), [
                'currency_balances' => [['currency_id' => $this->catalog['usd_id'], 'credit_limit' => 50]],
            ])
            ->assertOk();
        $partly = $this->draftInvoice(100);
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$partly}/payments"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'amount' => 60],
            ]])
            ->assertOk();
        $posted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$partly}/post"))
            ->assertOk()
            ->json('data');
        $this->assertEqualsWithDelta((float) $posted['grand_total'] - 60, (float) $posted['net_to_pay'], 0.001);

        // Payments cannot be more than the invoice.
        $over = $this->draftInvoice(10);
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$over}/payments"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'amount' => 20],
            ]])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$over}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL');

        // A walk-in customer pays everything at the counter.
        $walkIn = $this->tenant->run(fn (): string => (string) app(WalkInCustomerService::class)->ensure()->id);
        $walkInInvoice = $this->draftInvoice(30, ['customer_id' => $walkIn]);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$walkInInvoice}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_WALK_IN_MUST_PAY');

        // The cash/bank account must be in the invoice currency.
        $eurBank = $this->createCashBank('EURB', '5110', 'eur_id', 'bank');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$over}/payments"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'cash_bank_account_id' => $eurBank, 'amount' => 5],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_CASH_BANK_CURRENCY_MISMATCH');
        $this->assertNotSame($bank, $eurBank);
    }

    public function test_payment_account_comes_from_the_method_links_by_currency_and_branch(): void
    {
        [$usdCash] = $this->tillSetup();
        $branchId = (int) $this->tenant->run(fn () => Branch::query()->where('is_default', true)->value('id'));
        $eurShared = $this->createCashBank('EURC', '5110', 'eur_id', 'cash');
        $eurOwn = $this->createCashBank('EURC2', '5050', 'eur_id', 'cash', $branchId);
        $method = $this->catalog['cash_method_id'];
        $link = fn (array $ids) => $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/payment-methods/{$method}"), [...$this->paymentMethodPayload($method), 'cash_bank_account_ids' => $ids]);
        $eurInvoice = $this->draftInvoice(50, ['currency_id' => $this->catalog['eur_id'], 'exchange_rate' => 2.8]);
        $payCash = fn (string $invoice) => $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoice}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'amount' => 5],
            ]]);

        // Shared EUR account only: it is used.
        $link([$usdCash, $eurShared])->assertOk()->assertJsonCount(2, 'data.cash_bank_accounts');
        $payCash($eurInvoice)->assertOk()->assertJsonPath('data.payments.0.cash_bank_account_id', $eurShared);

        // The branch's own EUR account wins over the shared one; USD invoices still use the USD account.
        $link([$usdCash, $eurShared, $eurOwn])->assertOk();
        $payCash($eurInvoice)->assertOk()->assertJsonPath('data.payments.0.cash_bank_account_id', $eurOwn);
        $payCash($this->draftInvoice(20))->assertOk()->assertJsonPath('data.payments.0.cash_bank_account_id', $usdCash);

        // Two shared EUR accounts would be ambiguous.
        $eurShared2 = $this->createCashBank('EURC3', '2811', 'eur_id', 'bank');
        $link([$eurShared, $eurShared2])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_METHOD_ACCOUNT_DUPLICATE_BRANCH');

        // No EUR account linked: nothing to pick.
        $link([$usdCash])->assertOk();
        $payCash($eurInvoice)->assertOk()->assertJsonPath('data.payments.0.cash_bank_account_id', null);
    }

    public function test_default_payment_method_is_per_branch(): void
    {
        $cash = $this->catalog['cash_method_id'];
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/payment-methods/{$cash}"), [...$this->paymentMethodPayload($cash), 'is_default' => true])
            ->assertOk()
            ->assertJsonPath('data.is_default', true);

        $card = (int) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/payment-methods'), [
                'code' => 'CARD2', 'name' => 'Card', 'type' => 'card', 'requires_reference' => false,
                'supports_change' => false, 'is_default' => true, 'is_active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_default', true)
            ->json('data.id');

        // One default per branch: Cash is no longer the default here.
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/payment-methods/{$cash}"))->assertJsonPath('data.is_default', false);
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/payment-methods/{$card}"), [...$this->paymentMethodPayload($card), 'is_default' => false])
            ->assertOk()
            ->assertJsonPath('data.is_default', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentMethodPayload(int $id): array
    {
        return collect($this->asTenantRequest($this->token)->getJson($this->tenantUrl("/payment-methods/{$id}"))->json('data'))
            ->only(['code', 'name', 'type', 'currency_id', 'account_id', 'requires_reference', 'supports_change', 'is_default', 'is_active', 'notes'])
            ->all();
    }

    public function test_record_payment_after_post_creates_receipts_for_the_open_amount(): void
    {
        [$cash, $bank, $card] = $this->tillSetup();
        $invoice = $this->draftInvoice(100);
        $posted = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/post"))->assertOk()->json('data');
        $open = (float) $posted['net_to_pay'];

        // Only a posted invoice, and never more than what is open.
        $draft = $this->draftInvoice(10);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$draft}/payments/record"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'amount' => 5],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_POSTED');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoice}/payments/record"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'amount' => $open + 1],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL');

        $rest = round($open - 30, 2);
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoice}/payments/record"), ['payments' => [
                ['payment_method_id' => $this->catalog['cash_method_id'], 'amount' => 30],
            ]])
            ->assertOk()
            ->assertJsonPath('data.payments.0.amount', '30.0000');
        $after = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoice}/payments/record"), ['payment_date' => now()->toDateString(), 'payments' => [
                ['payment_method_id' => $card, 'cash_bank_account_id' => $bank, 'amount' => $rest, 'reference' => 'TX-2'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.net_to_pay', '0.0000')
            ->json('data');
        $this->assertCount(2, $after['payments']);
        $this->assertNotNull($after['payments'][1]['receipt_number']);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '30.0000');
        $this->assertBalance(0, self::RECEIVABLE);

        // Reversing the invoice reverses these receipts too.
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '0.0000');
    }

    public function test_currency_revaluation_reprices_foreign_balances_and_reverses_next_day(): void
    {
        // 1 USD (primary) = 3.5 EUR when booked. EUR stands in for LBP.
        $eur = $this->catalog['eur_id'];
        $eurCash = $this->createCashBank('EURC', '5110', 'eur_id', 'cash');
        $method = $this->catalog['cash_method_id'];

        // An EUR invoice left open (customer owes EUR) and one paid in EUR cash.
        $open = $this->draftInvoice(35, ['currency_id' => $eur, 'exchange_rate' => 3.5]);
        $openGrand = (float) $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$open}/post"))->assertOk()->json('data.grand_total');
        $paid = $this->draftInvoice(70, ['currency_id' => $eur, 'exchange_rate' => 3.5]);
        $paidGrand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$paid}"))->json('data.grand_total');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$paid}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'cash_bank_account_id' => $eurCash, 'amount' => $paidGrand],
            ]])
            ->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$paid}/post"))->assertOk();
        $receivableBefore = $openGrand / 3.5;

        // At that day's rate nothing changes.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/currency-revaluations'), ['date' => '2026-09-30', 'rates' => [$eur => 3.5]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENCY_REVALUATION_EMPTY');

        // EUR weakens to 4: both balances lose value. USD balances never appear.
        $preview = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/currency-revaluations/preview?date=2026-09-30&rates['.$eur.']=4'))
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $preview['lines']);
        foreach ($preview['lines'] as $line) {
            $this->assertSame($eur, $line['currency_id']);
        }
        $customerLine = collect($preview['lines'])->firstWhere('party_type', 'customer');
        $this->assertEqualsWithDelta($openGrand, (float) $customerLine['foreign_balance'], 0.0001);
        $this->assertEqualsWithDelta($openGrand / 4 - $openGrand / 3.5, (float) $customerLine['difference'], 0.011);
        $expectedLoss = ($openGrand + $paidGrand) / 3.5 - ($openGrand + $paidGrand) / 4;
        $this->assertEqualsWithDelta($expectedLoss, (float) $preview['loss'], 0.021);
        $this->assertEqualsWithDelta(0, (float) $preview['gain'], 0.0001);

        // Post: the loss is on 30 Sep, the reversal on 1 Oct.
        $row = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/currency-revaluations'), ['date' => '2026-09-30', 'rates' => [$eur => 4]])
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-30')
            ->assertJsonPath('data.reversal_date', '2026-10-01')
            ->json('data');
        $this->assertEqualsWithDelta((float) $preview['loss'], (float) $row['loss'], 0.0001);
        $this->assertEqualsWithDelta(-($openGrand / 4), $this->balanceAt(self::RECEIVABLE, '2026-09-30') * -1, 0.011);
        $this->assertEqualsWithDelta((float) $preview['loss'], $this->balanceAt('6753', '2026-09-30'), 0.0001);

        // The EUR amounts never move; after the reversal everything is back to the booked value.
        $this->tenant->run(function () use ($eur): void {
            $entry = JournalEntry::query()->where('source_type', JournalEntry::SOURCE_CURRENCY_REVALUATION)->where('source_event', 'post')->firstOrFail();
            $foreign = $entry->lines->where('currency_id', $eur);
            $this->assertCount(2, $foreign);
            foreach ($foreign as $line) {
                $this->assertEqualsWithDelta(0, (float) $line->amount_currency, 0.0001);
            }
        });
        $this->assertBalance($receivableBefore, self::RECEIVABLE);
        $this->assertBalance(0, '6753');

        // One per date, and never on or before the last one.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/accounting/currency-revaluations'), ['date' => '2026-09-30', 'rates' => [$eur => 4]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENCY_REVALUATION_OVERLAP');
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/currency-revaluations'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_undo', true);

        // Undo removes the entry and its reversal.
        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl('/accounting/currency-revaluations/2026-09-30'))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/accounting/currency-revaluations'))->assertOk()->assertJsonCount(0, 'data');
        $this->assertEqualsWithDelta($receivableBefore, $this->balanceAt(self::RECEIVABLE, '2026-09-30'), 0.0001);
        $this->tenant->run(fn () => $this->assertSame(0, JournalEntry::query()->where('source_type', JournalEntry::SOURCE_CURRENCY_REVALUATION)->count()));
    }

    private function balanceAt(string $code, string $to): float
    {
        return $this->tenant->run(function () use ($code, $to): float {
            $id = (int) Account::query()->where('code', $code)->value('id');
            $sum = app(AccountBalanceService::class)->totals(null, $to)[$id] ?? ['debit' => '0', 'credit' => '0'];

            return (float) $sum['debit'] - (float) $sum['credit'];
        });
    }

    public function test_cheque_at_sale_waits_in_cheques_to_collect_then_clears_or_bounces(): void
    {
        [, $bank] = $this->tillSetup();
        $cheque = $this->tenant->run(fn (): int => (int) PaymentMethod::query()->create([
            'code' => 'CHQ', 'name' => 'Cheque', 'type' => 'cheque', 'requires_reference' => false, 'is_active' => true,
        ])->id);
        $invoice = $this->draftInvoice(100);
        $grand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoice}"))->json('data.grand_total');

        // A cheque needs its number and due date.
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoice}/payments"), ['payments' => [
                ['payment_method_id' => $cheque, 'amount' => $grand],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_CHEQUE_DETAILS_REQUIRED');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoice}/payments"), ['payments' => [
                ['payment_method_id' => $cheque, 'amount' => $grand, 'cheque_number' => 'CHQ-77', 'cheque_bank' => 'BLOM', 'cheque_due_date' => '2026-10-15'],
            ]])
            ->assertOk()
            ->assertJsonPath('data.payments.0.cheque_number', 'CHQ-77');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/post"))->assertOk()->assertJsonPath('data.net_to_pay', '0.0000');

        // The money waits in Cheques to collect (5110), not in a bank.
        $this->assertBalance($grand, '5110');
        $this->assertBalance(0, self::RECEIVABLE);
        $row = $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/customer-cheques?status=in_hand'))->assertOk()->json('data.data.0');
        $this->assertSame('CHQ-77', $row['cheque_number']);
        $this->assertSame('BLOM', $row['bank_name']);

        // Clear into the bank.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-cheques/{$row['id']}/clear"), ['cash_bank_account_id' => $bank, 'date' => '2026-10-15'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');
        $this->assertBalance(0, '5110');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$bank}"))->assertJsonPath('data.balance', fn ($v): bool => abs((float) $v - $grand) < 0.001);

        // It bounces: the bank gives it back, the receipt is reversed and the invoice is open again.
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-cheques/{$row['id']}/bounce"))
            ->assertOk()
            ->assertJsonPath('data.status', 'bounced');
        $this->assertBalance(0, '5110');
        $this->assertBalance($grand, self::RECEIVABLE);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$bank}"))->assertJsonPath('data.balance', '0.0000');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoice}"))->assertJsonPath('data.net_to_pay', fn ($v): bool => abs((float) $v - $grand) < 0.001);
    }

    public function test_change_can_be_given_in_another_currency(): void
    {
        // 1 USD (primary) = 3.5 EUR. EUR stands in for LBP.
        [$cash] = $this->tillSetup();
        $eur = $this->catalog['eur_id'];
        $method = $this->catalog['cash_method_id'];
        // A first sale paid in EUR puts money in the Cash method's EUR box.
        $first = $this->draftInvoice(100);
        $firstGrand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$first}"))->json('data.grand_total');
        $eurIn = round($firstGrand * 3.5, 4);
        $eurCash = (int) $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$first}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'currency_id' => $eur, 'amount' => $eurIn],
            ]])
            ->assertOk()
            ->json('data.payments.0.cash_bank_account_id');
        $this->assertGreaterThan(0, $eurCash);
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$first}/post"))->assertOk();

        // Paid in USD with 10 $ extra; the change is handed back in EUR (35 EUR).
        $invoice = $this->draftInvoice(20);
        $grand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoice}"))->json('data.grand_total');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoice}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'amount' => $grand, 'tendered' => $grand + 10, 'change_currency_id' => $eur, 'change_amount' => 35],
            ]])
            ->assertOk()
            ->assertJsonPath('data.payments.0.change_cash_bank_account_id', $eurCash);
        $posted = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/post"))->assertOk()->json('data');
        $this->assertSame('0.0000', $posted['net_to_pay']);
        $this->assertNotNull($posted['payments'][0]['treasury_transfer_id']);

        // USD box kept the 10 $ extra; the EUR box paid out 35 EUR. Customers are settled.
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', fn ($v): bool => abs((float) $v - ($grand + 10)) < 0.001);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$eurCash}"))->assertJsonPath('data.balance', fn ($v): bool => abs((float) $v - ($eurIn - 35)) < 0.001);
        $this->assertBalance(0, self::RECEIVABLE);

        // The print lists the payment with its cash given and the change in EUR.
        $rows = $this->tenant->run(function () use ($invoice): array {
            $model = SalesInvoice::query()->with(['currency', 'payments.paymentMethod', 'payments.cashBankAccount', 'payments.currency', 'payments.changeCurrency', 'payments.customerReceipt.allocations'])->findOrFail($invoice);
            $labels = app(DocumentPdfRenderer::class)->labelsFor(DocumentPdfOptions::fromRequest(null, null));

            return DocumentPdfSupport::paymentRows($model->payments, $model, new DocumentMoney, $labels);
        });
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('EUR', implode(' ', $rows[0]['details']));
        $this->asTenantRequest($this->token)->get($this->tenantUrl("/sales-invoices/{$invoice}/pdf?template=receipt"))->assertOk();

        // Reversing the invoice undoes the change transfer too.
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '0.0000');
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$eurCash}"))->assertJsonPath('data.balance', fn ($v): bool => abs((float) $v - $eurIn) < 0.001);
    }

    public function test_overpayment_can_be_kept_as_customer_credit_and_used_later(): void
    {
        $this->tillSetup();
        $method = $this->catalog['cash_method_id'];
        $customer = $this->catalog['customer_id'];
        $usd = $this->catalog['usd_id'];

        // Paid 20 $ more than the invoice: refused unless the extra is kept as credit.
        $first = $this->draftInvoice(50);
        $grand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$first}"))->json('data.grand_total');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$first}/payments"), ['payments' => [['payment_method_id' => $method, 'amount' => $grand + 20]]])
            ->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$first}/post"))->assertStatus(422)->assertJsonPath('code', 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$first}/payments"), ['keep_extra_as_credit' => true, 'payments' => [['payment_method_id' => $method, 'amount' => $grand + 20]]])
            ->assertOk();
        $posted = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$first}/post"))->assertOk()->assertJsonPath('data.net_to_pay', '0.0000')->json('data');
        $receiptId = (string) $posted['payments'][0]['customer_receipt_id'];
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/customer-receipts/{$receiptId}"))->assertJsonPath('data.unapplied_amount', '20.0000');
        $this->assertBalance(-20, self::RECEIVABLE);
        $credit = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/customer-receipts/available-credit?customer_id={$customer}&currency_id={$usd}"))->assertOk()->json('data');
        $this->assertCount(1, $credit);
        $this->assertEqualsWithDelta(20, (float) $credit[0]['in_invoice_currency'], 0.0001);

        // A later invoice is paid with that credit.
        $second = $this->draftInvoice(10);
        $secondGrand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$second}"))->json('data.grand_total');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$second}/payments"), ['payments' => [['kind' => 'credit', 'amount' => $secondGrand]]])
            ->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$second}/post"))->assertOk()->assertJsonPath('data.net_to_pay', '0.0000');
        $this->assertBalance(-(20 - $secondGrand), self::RECEIVABLE);

        // Not more than what is left.
        $third = $this->draftInvoice(100);
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$third}/payments"), ['payments' => [['kind' => 'credit', 'amount' => 50]]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_CREDIT_EXCEEDED');

        // Credit applied from the receipt to an EUR invoice (2 $ = 7 EUR).
        $eurInvoice = $this->draftInvoice(35, ['currency_id' => $this->catalog['eur_id'], 'exchange_rate' => 3.5]);
        $eurOpen = (float) $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$eurInvoice}/post"))->assertOk()->json('data.net_to_pay');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/apply-credit"), ['allocations' => [['sales_invoice_id' => $eurInvoice, 'amount' => 2]]])
            ->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$eurInvoice}"))->assertJsonPath('data.net_to_pay', fn ($v): bool => abs((float) $v - ($eurOpen - 7)) < 0.001);

        // Reversing the invoice paid by credit gives the credit back to the receipt.
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$second}/reverse"))->assertOk();
        $left = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/customer-receipts/{$receiptId}"))->json('data.unapplied_amount');
        $this->assertEqualsWithDelta(18, (float) $left, 0.0001);
    }

    public function test_payment_at_sale_can_be_split_across_currencies(): void
    {
        // 1 USD (primary) = 3.5 EUR. EUR stands in for a second cash currency such as LBP.
        [$cash] = $this->tillSetup();
        $eurCash = $this->createCashBank('EURC', '5110', 'eur_id', 'cash');
        $method = $this->catalog['cash_method_id'];
        $eur = $this->catalog['eur_id'];

        $invoice = $this->draftInvoice(100);
        $grand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoice}"))->json('data.grand_total');
        $inEur = round(($grand - 40) * 3.5, 4);

        $payments = $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$invoice}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'amount' => 40],
                ['payment_method_id' => $method, 'cash_bank_account_id' => $eurCash, 'currency_id' => $eur, 'amount' => $inEur],
            ]])
            ->assertOk()
            ->json('data.payments');
        $this->assertSame($this->catalog['usd_id'], $payments[0]['currency_id']);
        $this->assertSame($eur, $payments[1]['currency_id']);
        $this->assertEqualsWithDelta(3.5, (float) $payments[1]['exchange_rate'], 0.0000001);

        $posted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoice}/post"))
            ->assertOk()
            ->assertJsonPath('data.net_to_pay', '0.0000')
            ->json('data');
        $this->assertBalance(0, self::RECEIVABLE);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '40.0000');
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/cash-bank-accounts/{$eurCash}"))
            ->assertJsonPath('data.balance', fn ($v): bool => abs((float) $v - $inEur) < 0.001);
        $eurReceipt = (string) $posted['payments'][1]['customer_receipt_id'];
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/customer-receipts/{$eurReceipt}"))
            ->assertJsonPath('data.currency_id', $eur);

        // A rate typed on the line is used instead of today's rate.
        $typed = $this->draftInvoice(10);
        $typedGrand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$typed}"))->json('data.grand_total');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$typed}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'cash_bank_account_id' => $eurCash, 'currency_id' => $eur, 'exchange_rate' => 4, 'amount' => round($typedGrand * 4, 4)],
            ]])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$typed}/post"))
            ->assertOk()
            ->assertJsonPath('data.net_to_pay', '0.0000');

        // Paying more than the invoice in the other currency is refused.
        $over = $this->draftInvoice(10);
        $overGrand = (float) $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$over}"))->json('data.grand_total');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$over}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'cash_bank_account_id' => $eurCash, 'currency_id' => $eur, 'amount' => round(($overGrand + 5) * 3.5, 4)],
            ]])
            ->assertOk();
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$over}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_PAYMENTS_EXCEED_TOTAL');

        // The cash/bank account must match the line's currency.
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$over}/payments"), ['payments' => [
                ['payment_method_id' => $method, 'cash_bank_account_id' => $cash, 'currency_id' => $eur, 'amount' => 5],
            ]])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PAYMENT_CASH_BANK_CURRENCY_MISMATCH');

        // Money received later can also come in the other currency.
        $later = $this->draftInvoice(20);
        $open = (float) $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$later}/post"))->assertOk()->json('data.net_to_pay');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$later}/payments/record"), ['payments' => [
                ['payment_method_id' => $method, 'cash_bank_account_id' => $eurCash, 'currency_id' => $eur, 'amount' => round($open * 3.5, 4)],
            ]])
            ->assertOk()
            ->assertJsonPath('data.net_to_pay', '0.0000')
            ->assertJsonPath('data.payments.0.currency_id', $eur);

        // Reversing the invoice takes the money back out of both cash boxes.
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/cash-bank-accounts/{$cash}"))->assertJsonPath('data.balance', '0.0000');
    }

    /**
     * A cashier (Admin permissions without discount_override) with a role discount limit.
     */
    private function cashierToken(float $maxDiscount): string
    {
        $token = $this->tenant->run(function () use ($maxDiscount): string {
            $admin = Role::query()->where('name', 'Admin')->firstOrFail();
            $cashier = Role::query()->create(['name' => 'Cashier', 'is_active' => true, 'max_discount_percent' => $maxDiscount]);
            $overrideId = (int) Permission::query()->where('resource_key', 'discount_override')->value('id');
            foreach (RolePermission::query()->where('role_id', $admin->id)->get() as $row) {
                if ((int) $row->permission_id === $overrideId) {
                    continue;
                }
                RolePermission::query()->create([...$row->only($row->getFillable()), 'role_id' => $cashier->id]);
            }
            $user = User::factory()->create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'is_active' => true]);
            $user->branches()->attach(Branch::query()->where('is_default', true)->value('id'), ['role_id' => $cashier->id]);
            app(PermissionService::class)->invalidateCacheForAllUsers();

            return $user->createToken('cashier')->plainTextToken;
        });
        $this->app['auth']->forgetGuards();

        return $token;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function draftInvoice(float $price, array $overrides = [], ?string $token = null): string
    {
        return (string) $this->asTenantRequest($token ?? $this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), array_merge([
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [['item_id' => $this->catalog['service_item_id'], 'quantity' => 1, 'unit_price' => $price]],
            ], $overrides))
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * Cash box (default for the Cash method), bank account, and a Card method.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function tillSetup(): array
    {
        $cash = $this->catalog['cash_box_id'];
        $bank = $this->createCashBank('BANK', '5120', 'usd_id', 'bank');
        $card = $this->tenant->run(function (): int {
            PaymentMethod::query()->whereKey($this->catalog['cash_method_id'])->update(['supports_change' => true]);

            return (int) PaymentMethod::query()->create([
                'code' => 'CARD',
                'name' => 'Card',
                'type' => 'card',
                'requires_reference' => true,
                'is_active' => true,
            ])->id;
        });

        return [$cash, $bank, $card];
    }

    private function createCashBank(string $code, string $ledgerCode, string $currencyKey, string $type, ?int $branchId = null): int
    {
        return (int) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/cash-bank-accounts'), [
                'code' => $code,
                'name' => 'Account '.$code,
                'type' => $type,
                'currency_id' => $this->catalog[$currencyKey],
                'account_id' => $this->accountId($ledgerCode),
                'branch_id' => $branchId,
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function branchBalance(string $code, int $branchId): float
    {
        return $this->tenant->run(function () use ($code, $branchId): float {
            $id = (int) Account::query()->where('code', $code)->value('id');
            $sum = app(AccountBalanceService::class)->totals(null, null, null, ['branch_id' => $branchId])[$id] ?? ['debit' => '0', 'credit' => '0'];

            return (float) $sum['debit'] - (float) $sum['credit'];
        });
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

        // Money taken in cash goes through these cash boxes (USD on 5310 Cash).
        $cashBox = $this->linkCashBank((int) $cash->id, (int) $usd->id, self::CASH);
        $this->linkCashBank((int) $cash->id, (int) $eur->id, '2812');

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'supplier_id' => (string) $supplier->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
            'cash_method_id' => (int) $cash->id,
            'cash_box_id' => $cashBox,
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
