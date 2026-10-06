<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=SalesCreditNoteApiTest
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

        $this->setUpTenant('sales_credit_note_v1');
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

    public function test_post_credits_open_amount_and_reverse_restores_it(): void
    {
        $invoiceId = $this->postSalesInvoice(100, 2);
        $lineId = $this->firstInvoiceLineId($invoiceId);

        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'credit_date' => '2026-09-05',
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $noteId = (string) $created->json('data.id');
        $this->assertMatchesRegularExpression('/^CN-\d+$/', (string) $created->json('data.credit_note_number'));
        $this->assertEqualsWithDelta(50.0, (float) $created->json('data.grand_total'), 0.001);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $invoice = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))
            ->assertOk();
        $this->assertEqualsWithDelta(50.0, (float) $invoice->json('data.credited_total'), 0.001);
        $this->assertEqualsWithDelta(50.0, (float) $invoice->json('data.net_to_pay'), 0.001);
        $this->assertFalse((bool) $invoice->json('data.can_reverse'));
        $this->assertFalse((bool) $invoice->json('data.can_reissue'));

        $this->tenant->run(function () use ($noteId): void {
            $entry = CustomerLedgerEntry::query()
                ->where('reference_type', LedgerReferenceType::CreditNote)
                ->where('reference_id', $noteId)
                ->orderBy('id')
                ->first();
            $this->assertNotNull($entry);
            $this->assertEqualsWithDelta(50.0, (float) $entry->credit, 0.001);
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_HAS_CREDITS');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/reissue"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_HAS_CREDITS');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $restored = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))
            ->assertOk();
        $this->assertEqualsWithDelta(0.0, (float) $restored->json('data.credited_total'), 0.001);
        $this->assertEqualsWithDelta(100.0, (float) $restored->json('data.net_to_pay'), 0.001);
        $this->assertTrue((bool) $restored->json('data.can_reverse'));
    }

    public function test_rejects_credit_that_exceeds_unpaid_amount(): void
    {
        $invoiceId = $this->postSalesInvoice(100, 1);
        $lineId = $this->firstInvoiceLineId($invoiceId);

        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 60,
                'allocations' => [[
                    'sales_invoice_id' => $invoiceId,
                    'amount' => 60,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))
            ->assertOk();

        $noteId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CREDIT_NOTE_EXCEEDS_OPEN');
    }

    public function test_post_returns_sale_stock(): void
    {
        $this->tenant->run(function (): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['stock_item_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '10',
                unitCost: '5',
                itemUomId: null,
                notes: 'opening',
                userId: null,
            ));
        });

        $invoiceId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 3,
                    'unit_price' => 12,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/post"))
            ->assertOk();

        $lineId = $this->firstInvoiceLineId((string) $invoiceId);
        $noteId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 2,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($noteId): void {
            $movement = StockMovement::query()
                ->where('reference_type', 'sales_credit_note')
                ->where('reference_id', $noteId)
                ->first();
            $this->assertNotNull($movement);
            $this->assertSame(StockMovementType::SaleReturn, $movement->type);
            $this->assertSame('2.000000', number_format((float) $movement->quantity_delta, 6, '.', ''));
        });
    }

    public function test_source_lines_omit_fully_credited_quantities(): void
    {
        $invoiceId = $this->postSalesInvoice(100, 1);
        $lineId = $this->firstInvoiceLineId($invoiceId);
        $noteId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/post"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-credit-notes/source/{$invoiceId}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'CREDIT_NOTE_INVOICE_CLOSED');
    }

    public function test_source_lines_reduce_remaining_quantity_after_partial_credit(): void
    {
        $invoiceId = $this->postSalesInvoice(100, 4);
        $lineId = $this->firstInvoiceLineId($invoiceId);

        $noteId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => 1,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/post"))
            ->assertOk();

        $source = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-credit-notes/source/{$invoiceId}"))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $source);
        $this->assertEqualsWithDelta(3.0, (float) $source[0]['remaining_quantity'], 0.000001);
        $this->assertEqualsWithDelta(3.0, (float) $source[0]['suggested_quantity'], 0.000001);
    }

    public function test_suggested_quantity_scales_to_unpaid_and_posts(): void
    {
        $invoiceId = $this->postSalesInvoice(100, 1);
        $lineId = $this->firstInvoiceLineId($invoiceId);

        $receiptId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/customer-receipts'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'payment_method_id' => $this->catalog['cash_method_id'],
                'payment_date' => '2026-09-02',
                'amount' => 60,
                'allocations' => [[
                    'sales_invoice_id' => $invoiceId,
                    'amount' => 60,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/customer-receipts/{$receiptId}/post"))
            ->assertOk();

        $source = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-credit-notes/source/{$invoiceId}"))
            ->assertOk()
            ->json('data.0');

        $this->assertEqualsWithDelta(1.0, (float) $source['remaining_quantity'], 0.000001);
        $this->assertEqualsWithDelta(0.4, (float) $source['suggested_quantity'], 0.000001);

        $noteId = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-credit-notes'), [
                'sales_invoice_id' => $invoiceId,
                'lines' => [[
                    'sales_invoice_line_id' => $lineId,
                    'quantity' => $source['suggested_quantity'],
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-credit-notes/{$noteId}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $invoice = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-invoices/{$invoiceId}"))
            ->assertOk();
        $this->assertEqualsWithDelta(40.0, (float) $invoice->json('data.credited_total'), 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->json('data.net_to_pay'), 0.001);
    }

    private function postSalesInvoice(float $amount, int $quantity = 1): string
    {
        $unitPrice = $quantity > 0 ? $amount / $quantity : $amount;
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['usd_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        return (string) $id;
    }

    private function firstInvoiceLineId(string $invoiceId): int
    {
        return (int) $this->tenant->run(function () use ($invoiceId): int {
            $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoiceId)->orderBy('id')->firstOrFail();

            return (int) $line->id;
        });
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
        ]);
        app(CompanySettingService::class)->forgetCache();

        $warehouse = Warehouse::query()->create([
            'name' => 'Sales WH',
            'shortcut_name' => 'SWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => true,
            'is_default_production' => false,
            'is_default_purchase' => false,
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
        $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();

        $service = Item::query()->create([
            'name' => 'Service item',
            'item_code' => 'SVC-1',
            'item_type_id' => $serviceType->id,
            'unit_group_id' => $unitGroup->id,
            'base_uom_id' => $uom->id,
            'vat_group_id' => $vat->id,
            'track_inventory' => false,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
        ]);
        ItemUom::query()->create([
            'item_id' => $service->id,
            'uom_id' => $uom->id,
            'conversion_factor' => 1,
            'selling_price' => 50,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
        ]);

        $stock = Item::query()->create([
            'name' => 'Stock item',
            'item_code' => 'STK-1',
            'item_type_id' => $inventoryType->id,
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
            'item_id' => $stock->id,
            'uom_id' => $uom->id,
            'conversion_factor' => 1,
            'selling_price' => 12,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Invoice Customer',
            'type' => 'individual',
            'status' => 'active',
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
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
            'cash_method_id' => (int) $cash->id,
        ];
    }
}
