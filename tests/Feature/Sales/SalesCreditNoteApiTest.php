<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\ModuleEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres. Run with: php artisan test --group=tenant-db --filter=SalesCreditNoteApiTest
 */
#[Group('tenant-db')]
class SalesCreditNoteApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('sales_credit_v1');
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

    public function test_return_of_two_from_five_credits_the_invoice_and_restocks_at_original_cost(): void
    {
        $this->receiveStock('10', '5');
        $invoiceId = $this->postStockInvoice(5, 20);
        $lineId = $this->invoiceLineId($invoiceId);

        $note = $this->postCredit($invoiceId, [[
            'sales_invoice_line_id' => $lineId,
            'quantity' => 2,
            'restock' => true,
            'warehouse_id' => $this->catalog['warehouse_id'],
        ]]);

        $this->assertSame('44.4000', $note['grand_total']);
        $this->assertSame('4.4000', $note['tax_total']);

        $invoice = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))->assertOk()->json('data');
        $this->assertSame('44.4000', $invoice['credited_total']);
        $this->assertSame('66.6000', $invoice['creditable_total']);
        $this->assertSame('66.6000', $invoice['net_to_pay']);
        $this->assertEqualsWithDelta(66.6, $this->customerBalance(), 0.0001);

        $this->tenant->run(function () use ($note): void {
            $movement = StockMovement::query()
                ->where('reference_type', 'sales_credit_note')
                ->where('reference_id', $note['id'])
                ->first();
            $this->assertNotNull($movement);
            $this->assertSame('2.000000', number_format((float) $movement->quantity_delta, 6, '.', ''));
            $this->assertEqualsWithDelta(5.0, (float) $movement->unit_cost, 0.0001);
        });

        $remaining = $this->postCredit($invoiceId, [[
            'sales_invoice_line_id' => $lineId,
            'quantity' => 3,
            'warehouse_id' => $this->catalog['warehouse_id'],
        ]]);
        $this->assertSame('66.6000', $remaining['grand_total']);
        $closed = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))->assertOk()->json('data');
        $this->assertSame('0.0000', $closed['creditable_total']);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'type' => 'return',
                'credit_date' => '2026-09-02',
                'reason' => 'Too many',
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 1,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_CREDIT_NOTE_QTY_EXCEEDED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_HAS_CREDIT_NOTES');
    }

    public function test_bundle_return_restocks_components_at_the_sale_cost(): void
    {
        $bundle = $this->tenant->run(function (): array {
            $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
            $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
            $vat = VatGroup::query()->where('abrv', 'VAT11')->firstOrFail();
            $childType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();
            $bundleType = ItemType::query()->where('code', 'BUNDLE')->firstOrFail();
            $child = Item::query()->create([
                'name' => 'Bundle child',
                'item_code' => 'BND-C',
                'item_type_id' => $childType->id,
                'unit_group_id' => $unitGroup->id,
                'base_uom_id' => $uom->id,
                'vat_group_id' => $vat->id,
                'track_inventory' => true,
                'track_lots' => false,
                'allow_sale' => true,
                'allow_purchase' => true,
                'is_active' => true,
            ]);
            $parent = Item::query()->create([
                'name' => 'Bundle parent',
                'item_code' => 'BND-P',
                'item_type_id' => $bundleType->id,
                'unit_group_id' => $unitGroup->id,
                'base_uom_id' => $uom->id,
                'vat_group_id' => $vat->id,
                'track_inventory' => false,
                'track_lots' => false,
                'allow_sale' => true,
                'allow_purchase' => false,
                'is_active' => true,
            ]);
            foreach ([$child, $parent] as $item) {
                ItemUom::query()->create([
                    'item_id' => $item->id,
                    'uom_id' => $uom->id,
                    'conversion_factor' => 1,
                    'selling_price' => 30,
                    'cost_price' => 4,
                    'is_base' => true,
                    'is_default_sale' => true,
                ]);
            }
            BundleItem::query()->create([
                'bundle_item_id' => $parent->id,
                'child_item_id' => $child->id,
                'quantity' => 2,
            ]);

            return ['parent_id' => (string) $parent->id, 'child_id' => (string) $child->id];
        });

        $this->tenant->run(function () use ($bundle): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $bundle['child_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '20',
                unitCost: '2',
                itemUomId: null,
                notes: 'bundle child',
                userId: null,
            ));
        });

        $invoiceId = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/sales-invoices'), [
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['usd_id'],
            'invoice_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $bundle['parent_id'],
                'quantity' => 2,
                'unit_price' => 30,
                'warehouse_id' => $this->catalog['warehouse_id'],
            ]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/post"))->assertOk();

        $note = $this->postCredit((string) $invoiceId, [[
            'sales_invoice_line_id' => $this->invoiceLineId((string) $invoiceId),
            'quantity' => 1,
            'restock' => true,
            'warehouse_id' => $this->catalog['warehouse_id'],
        ]]);

        $this->tenant->run(function () use ($note, $bundle): void {
            $movement = StockMovement::query()
                ->where('reference_type', 'sales_credit_note')
                ->where('reference_id', $note['id'])
                ->where('item_id', $bundle['child_id'])
                ->first();
            $this->assertNotNull($movement);
            $this->assertSame('sales_return', $movement->type->value);
            $this->assertEqualsWithDelta(2.0, (float) $movement->quantity_delta, 0.0001);
            $this->assertEqualsWithDelta(2.0, (float) $movement->unit_cost, 0.0001);
        });
    }

    public function test_allowance_posts_sales_discount_and_moves_no_stock(): void
    {
        $invoiceId = $this->postStockInvoice(1, 100);
        $lineId = $this->invoiceLineId($invoiceId);

        $note = $this->postCredit($invoiceId, [[
            'sales_invoice_line_id' => $lineId,
            'quantity' => 0,
            'line_total' => 10,
        ]], 'allowance');

        $this->assertSame('0.9900', $note['tax_total']);
        $this->assertSame('10.0000', $note['grand_total']);
        $this->tenant->run(function () use ($note): void {
            $this->assertSame(0, StockMovement::query()->where('reference_id', $note['id'])->count());
            $accountId = (int) Account::query()->where('code', '7191')->value('id');
            $debit = JournalEntryLine::query()
                ->where('account_id', $accountId)
                ->whereHas('journalEntry', fn ($query) => $query->where('source_id', $note['id'])->where('source_event', 'post'))
                ->sum('debit');
            $this->assertEqualsWithDelta(9.01, (float) $debit, 0.0001);
        });
    }

    public function test_credit_on_a_paid_invoice_stays_open_and_can_be_applied_and_refunded(): void
    {
        $first = $this->postStockInvoice(1, 100);
        $receiptId = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/customer-receipts'), [
            'customer_id' => $this->catalog['customer_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['method_id'],
            'payment_date' => '2026-09-01',
            'amount' => 111,
            'allocations' => [['sales_invoice_id' => $first, 'amount' => 111]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))->assertOk();

        $note = $this->postCredit($first, [[
            'sales_invoice_line_id' => $this->invoiceLineId($first),
            'quantity' => 1,
            'restock' => false,
        ]]);
        $this->assertSame('111.0000', $note['open_credit']);
        $this->assertEqualsWithDelta(-111.0, $this->customerBalance(), 0.0001);

        $second = $this->postStockInvoice(1, 20);
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-credit-notes/{$note['id']}/apply"), [
            'allocations' => [['sales_invoice_id' => $second, 'amount' => 10]],
        ])->assertOk();
        $open = $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/customer-receipts/open-invoices?customer_id='.$this->catalog['customer_id'].'&currency_id='.$this->catalog['usd_id']))->assertOk()->json('data');
        $match = collect($open)->firstWhere('id', $second);
        $this->assertNotNull($match);
        $this->assertSame('12.2000', $match['net_to_pay']);

        $refund = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/customer-refunds'), [
            'customer_id' => $this->catalog['customer_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['method_id'],
            'refund_date' => '2026-09-03',
            'amount' => 10,
            'allocations' => [['sales_credit_note_id' => $note['id'], 'amount' => 10]],
        ])->assertCreated()->json('data');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-refunds/{$refund['id']}/post"))->assertOk();

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$note['id']}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_CREDIT_NOTE_HAS_REFUNDS');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-refunds/{$refund['id']}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-credit-notes/{$note['id']}/reverse"))->assertOk();
        $restored = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-invoices/{$second}"))->json('data');
        $this->assertSame('22.2000', $restored['net_to_pay']);
    }

    public function test_restock_false_posts_no_stock_or_inventory_journal(): void
    {
        $this->receiveStock('5', '4');
        $invoiceId = $this->postStockInvoice(1, 20);
        $note = $this->postCredit($invoiceId, [[
            'sales_invoice_line_id' => $this->invoiceLineId($invoiceId),
            'quantity' => 1,
            'restock' => false,
        ]]);

        $this->tenant->run(function () use ($note): void {
            $this->assertSame(0, StockMovement::query()->where('reference_type', 'sales_credit_note')->where('reference_id', $note['id'])->count());
            $inventoryId = (int) Account::query()->where('code', '3710')->value('id');
            $count = JournalEntryLine::query()
                ->where('account_id', $inventoryId)
                ->whereHas('journalEntry', fn ($query) => $query->where('source_id', $note['id']))
                ->count();
            $this->assertSame(0, $count);
        });
    }

    public function test_tenant_without_accounting_posts_no_journal(): void
    {
        app(ModuleEntitlementService::class)->syncTenantModules(
            $this->tenant,
            collect(config('modules.catalog'))->keys()->reject(fn (string $code): bool => $code === 'accounting')->values()->all(),
        );
        $invoiceId = $this->postStockInvoice(1, 20);
        $note = $this->postCredit($invoiceId, [[
            'sales_invoice_line_id' => $this->invoiceLineId($invoiceId),
            'quantity' => 1,
            'restock' => false,
        ]]);
        $this->tenant->run(function () use ($note): void {
            $this->assertSame(0, JournalEntry::query()->where('source_id', $note['id'])->count());
        });
    }

    public function test_applying_credit_across_different_rates_posts_an_exchange_difference(): void
    {
        $this->tenant->run(function (): void {
            $eur = Currency::query()->create([
                'name' => 'Euro',
                'code' => 'EUR',
                'iso_code' => 'EUR',
                'symbol' => '€',
                'is_active' => true,
            ]);
            CompanySetting::singleton()->update(['primary_currency_id' => $eur->id]);
            app(CompanySettingService::class)->forgetCache();
        });

        $first = $this->postForeignInvoice(1, 100, '1.10');
        $receiptId = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/customer-receipts'), [
            'customer_id' => $this->catalog['customer_id'],
            'currency_id' => $this->catalog['usd_id'],
            'exchange_rate' => '1.10',
            'payment_method_id' => $this->catalog['method_id'],
            'payment_date' => '2026-09-01',
            'amount' => 111,
            'allocations' => [['sales_invoice_id' => $first, 'amount' => 111]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))->assertOk();

        $note = $this->postCredit($first, [[
            'sales_invoice_line_id' => $this->invoiceLineId($first),
            'quantity' => 1,
            'restock' => false,
        ]]);
        $this->assertSame('111.0000', $note['open_credit']);

        $second = $this->postForeignInvoice(1, 20, '1.20');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-credit-notes/{$note['id']}/apply"), [
            'allocations' => [['sales_invoice_id' => $second, 'amount' => 10]],
        ])->assertOk();

        $this->tenant->run(function () use ($note): void {
            $entry = JournalEntry::query()
                ->where('source_type', 'sales_credit_note_apply')
                ->where('source_id', 'like', $note['id'].':%')
                ->where('source_event', 'post')
                ->first();
            $this->assertNotNull($entry);
            $totals = JournalEntryLine::query()
                ->where('journal_entry_id', $entry->id)
                ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
                ->first();
            $this->assertEqualsWithDelta((float) $totals->debit, (float) $totals->credit, 0.001);
            $this->assertGreaterThan(0, (float) $totals->debit);
        });
    }

    public function test_vat_report_shows_the_credit_note_as_negative_output(): void
    {
        $invoiceId = $this->postStockInvoice(1, 100);
        $this->postCredit($invoiceId, [[
            'sales_invoice_line_id' => $this->invoiceLineId($invoiceId),
            'quantity' => 1,
            'restock' => false,
        ]]);

        $report = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/vat-report?from=2026-09-01&to=2026-09-30'))
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0.0, (float) $report['output_vat'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $report['ledger_check']['output_difference'], 0.0001);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function postCredit(string $invoiceId, array $lines, string $type = 'return'): array
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'type' => $type,
                'credit_date' => '2026-09-02',
                'reason' => 'Customer return',
                'lines' => $lines,
            ])
            ->assertCreated()
            ->json('data');

        return $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$created['id']}/post"))
            ->assertOk()
            ->json('data');
    }

    private function postForeignInvoice(float $quantity, float $price, string $rate): string
    {
        $this->receiveStock((string) $quantity, '5');
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'exchange_rate' => $rate,
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['item_id'],
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))->assertOk();

        return (string) $id;
    }

    private function postStockInvoice(float $quantity, float $price): string
    {
        $this->receiveStock((string) $quantity, '5');
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['item_id'],
                    'quantity' => $quantity,
                    'unit_price' => $price,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))->assertOk();

        return (string) $id;
    }

    private function invoiceLineId(string $invoiceId): int
    {
        return (int) $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))
            ->json('data.lines.0.id');
    }

    private function receiveStock(string $quantity, string $cost): void
    {
        $this->tenant->run(function () use ($quantity, $cost): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['item_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: $quantity,
                unitCost: $cost,
                itemUomId: null,
                notes: 'opening',
                userId: null,
            ));
        });
    }

    private function customerBalance(): float
    {
        return (float) $this->tenant->run(fn () => CustomerLedgerEntry::query()
            ->where('customer_id', $this->catalog['customer_id'])
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as net')
            ->value('net'));
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
            'tax_enabled' => true,
            'tax_price_mode' => 'exclusive',
            'allow_negative_stock' => false,
            'price_decimal_places' => 2,
        ]);
        app(CompanySettingService::class)->forgetCache();

        $warehouse = Warehouse::query()->create([
            'name' => 'Credit WH',
            'shortcut_name' => 'CWH',
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
        $type = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();
        $item = Item::query()->create([
            'name' => 'Return item',
            'item_code' => 'RET-1',
            'item_type_id' => $type->id,
            'unit_group_id' => $unitGroup->id,
            'base_uom_id' => $uom->id,
            'vat_group_id' => $vat->id,
            'track_inventory' => true,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
        ]);
        ItemUom::query()->create([
            'item_id' => $item->id,
            'uom_id' => $uom->id,
            'conversion_factor' => 1,
            'selling_price' => 20,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Credit Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);
        $method = PaymentMethod::query()->create([
            'code' => 'CASH',
            'name' => 'Cash',
            'type' => 'cash',
            'requires_reference' => false,
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'warehouse_id' => (int) $warehouse->id,
            'item_id' => (string) $item->id,
            'customer_id' => (string) $customer->id,
            'method_id' => (int) $method->id,
        ];
    }
}
