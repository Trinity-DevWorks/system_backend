<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierLedgerEntry;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres. Run with: php artisan test --group=tenant-db --filter=PurchaseDebitNoteApiTest
 */
#[Group('tenant-db')]
class PurchaseDebitNoteApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('purchase_debit_v1');
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

    public function test_return_of_three_ships_stock_out_and_posts_the_price_difference(): void
    {
        $this->receiveStock('5', '2');
        $invoiceId = $this->postPurchaseInvoice(5, 10);
        $note = $this->postDebit($invoiceId, [[
            'purchase_invoice_line_id' => $this->invoiceLineId($invoiceId),
            'quantity' => 3,
            'warehouse_id' => $this->catalog['warehouse_id'],
        ]]);

        $this->assertSame('30.0000', $note['grand_total']);
        $invoice = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/purchase-invoices/{$invoiceId}"))->assertOk()->json('data');
        $this->assertSame('30.0000', $invoice['debited_total']);
        $this->assertSame('20.0000', $invoice['net_to_pay']);

        $this->tenant->run(function () use ($note): void {
            $movement = StockMovement::query()
                ->where('reference_type', 'purchase_debit_note')
                ->where('reference_id', $note['id'])
                ->first();
            $this->assertNotNull($movement);
            $this->assertSame('purchase_return', $movement->type->value);
            $this->assertEqualsWithDelta(-3.0, (float) $movement->quantity_delta, 0.0001);
            $this->assertEqualsWithDelta(7.0, (float) StockBalance::query()->where('item_id', $this->catalog['item_id'])->sum('quantity'), 0.0001);

            $accountId = (int) Account::query()->where('code', '6014')->value('id');
            $line = JournalEntryLine::query()
                ->where('account_id', $accountId)
                ->whereHas('journalEntry', fn ($query) => $query->where('source_id', $note['id'])->where('source_event', 'post'))
                ->first();
            $this->assertNotNull($line);
            $this->assertEqualsWithDelta(12.0, (float) $line->credit, 0.001);
            $this->assertJournalBalanced($note['id']);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$invoiceId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PURCHASE_INVOICE_HAS_DEBIT_NOTES');
    }

    public function test_return_is_rejected_when_on_hand_stock_is_short(): void
    {
        $invoiceId = $this->postPurchaseInvoice(5, 10);
        $salesId = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/sales-invoices'), [
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['usd_id'],
            'invoice_date' => '2026-09-01',
            'lines' => [[
                'item_id' => $this->catalog['item_id'],
                'quantity' => 4,
                'unit_price' => 20,
                'warehouse_id' => $this->catalog['warehouse_id'],
            ]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$salesId}/post"))->assertOk();

        $created = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/purchase-debit-notes'), [
            'purchase_invoice_id' => $invoiceId,
            'type' => 'return',
            'debit_date' => '2026-09-02',
            'reason' => 'Supplier return',
            'lines' => [[
                'purchase_invoice_line_id' => $this->invoiceLineId($invoiceId),
                'quantity' => 3,
                'warehouse_id' => $this->catalog['warehouse_id'],
            ]],
        ])->assertCreated()->json('data');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-debit-notes/{$created['id']}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_INSUFFICIENT');
    }

    public function test_debit_on_a_paid_invoice_stays_open_and_can_be_refunded(): void
    {
        $invoiceId = $this->postPurchaseInvoice(1, 50);
        $paymentId = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/supplier-payments'), [
            'supplier_id' => $this->catalog['supplier_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['method_id'],
            'payment_date' => '2026-09-01',
            'amount' => 50,
            'allocations' => [['purchase_invoice_id' => $invoiceId, 'amount' => 50]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/supplier-payments/{$paymentId}/post"))->assertOk();

        $note = $this->postDebit($invoiceId, [[
            'purchase_invoice_line_id' => $this->invoiceLineId($invoiceId),
            'quantity' => 1,
            'warehouse_id' => $this->catalog['warehouse_id'],
        ]]);
        $this->assertSame('50.0000', $note['open_credit']);

        $refund = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/supplier-refunds'), [
            'supplier_id' => $this->catalog['supplier_id'],
            'currency_id' => $this->catalog['usd_id'],
            'payment_method_id' => $this->catalog['method_id'],
            'refund_date' => '2026-09-03',
            'amount' => 10,
            'allocations' => [['purchase_debit_note_id' => $note['id'], 'amount' => 10]],
        ])->assertCreated()->json('data');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/supplier-refunds/{$refund['id']}/post"))->assertOk();

        $this->tenant->run(function () use ($note): void {
            $posted = PurchaseDebitNote::query()->findOrFail($note['id']);
            $open = (float) $posted->open_credit;
            $refunded = (float) $posted->refunded_total;
            $this->assertEqualsWithDelta(40.0, $open, 0.001);
            $this->assertEqualsWithDelta(10.0, $refunded, 0.001);
            $net = (float) SupplierLedgerEntry::query()
                ->where('supplier_id', $this->catalog['supplier_id'])
                ->selectRaw('COALESCE(SUM(credit - debit), 0) as net')
                ->value('net');
            $this->assertEqualsWithDelta(-40.0, $net, 0.001);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-debit-notes/{$note['id']}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PURCHASE_DEBIT_NOTE_HAS_REFUNDS');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/supplier-refunds/{$refund['id']}/reverse"))->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/purchase-debit-notes/{$note['id']}/reverse"))->assertOk();
        $restored = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/purchase-invoices/{$invoiceId}"))->json('data');
        $this->assertSame('0.0000', $restored['net_to_pay']);
        $this->assertSame('0.0000', $restored['debited_total']);
    }

    public function test_vat_report_shows_the_debit_note_as_negative_input(): void
    {
        $this->tenant->run(function (): void {
            CompanySetting::singleton()->update(['tax_enabled' => true, 'tax_price_mode' => 'exclusive']);
            app(CompanySettingService::class)->forgetCache();
        });

        $invoiceId = $this->postPurchaseInvoice(1, 100);
        $this->postDebit($invoiceId, [[
            'purchase_invoice_line_id' => $this->invoiceLineId($invoiceId),
            'quantity' => 1,
            'warehouse_id' => $this->catalog['warehouse_id'],
        ]]);

        $report = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/accounting/vat-report?from=2026-09-01&to=2026-09-30'))
            ->assertOk()
            ->json('data');

        $this->assertEqualsWithDelta(0.0, (float) $report['input_vat'], 0.0001);
        $this->assertEqualsWithDelta(0.0, (float) $report['ledger_check']['input_difference'], 0.0001);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function postDebit(string $invoiceId, array $lines): array
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-debit-notes'), [
                'purchase_invoice_id' => $invoiceId,
                'type' => 'return',
                'debit_date' => '2026-09-02',
                'reason' => 'Supplier return',
                'lines' => $lines,
            ])
            ->assertCreated()
            ->json('data');

        return $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-debit-notes/{$created['id']}/post"))
            ->assertOk()
            ->json('data');
    }

    private function postPurchaseInvoice(float $quantity, float $price): string
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
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

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/purchase-invoices/{$id}/post"))->assertOk();

        return (string) $id;
    }

    private function invoiceLineId(string $invoiceId): int
    {
        return (int) $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/purchase-invoices/{$invoiceId}"))
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

    private function assertJournalBalanced(string $sourceId): void
    {
        $totals = JournalEntryLine::query()
            ->whereHas('journalEntry', fn ($query) => $query->where('source_id', $sourceId)->where('source_event', 'post'))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit, COALESCE(SUM(credit), 0) as credit')
            ->first();
        $this->assertEqualsWithDelta((float) $totals->debit, (float) $totals->credit, 0.001);
        $this->assertGreaterThan(0, (float) $totals->debit);
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
            'tax_price_mode' => 'exclusive',
            'allow_negative_stock' => false,
            'price_decimal_places' => 2,
        ]);
        app(CompanySettingService::class)->forgetCache();

        $warehouse = Warehouse::query()->create([
            'name' => 'Debit WH',
            'shortcut_name' => 'DWH',
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
            'name' => 'Debit item',
            'item_code' => 'PDN-1',
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
            'cost_price' => 10,
            'is_base' => true,
            'is_default_sale' => true,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Debit Supplier',
            'is_active' => true,
        ]);
        $customer = Customer::query()->create([
            'name' => 'Debit Customer',
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
            'supplier_id' => (string) $supplier->id,
            'customer_id' => (string) $customer->id,
            'method_id' => (int) $method->id,
        ];
    }
}
