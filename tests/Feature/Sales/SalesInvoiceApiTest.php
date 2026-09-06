<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyPairRate;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=SalesInvoiceApiTest
 */
#[Group('tenant-db')]
class SalesInvoiceApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('sales_invoice_v1');
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

    public function test_create_syncs_tax_snapshots_and_assigns_number(): void
    {
        $payload = $this->baseInvoicePayload([
            'lines' => [[
                'item_id' => $this->catalog['service_item_id'],
                'quantity' => 2,
                'unit_price' => 10,
                'discount_percent' => 10,
            ]],
        ]);

        $create = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $payload)
            ->assertCreated()
            ->assertJsonPath('success', true);

        $id = $create->json('data.id');
        $this->assertNotEmpty($id);
        $this->assertMatchesRegularExpression('/^SI-\d+$/', (string) $create->json('data.invoice_number'));
        $this->assertEqualsWithDelta(18.0, (float) $create->json('data.lines.0.line_subtotal'), 0.001);
        $this->assertEqualsWithDelta(10.0, (float) $create->json('data.lines.0.tax_rate'), 0.001);
        $this->assertEqualsWithDelta(1.8, (float) $create->json('data.lines.0.tax_amount'), 0.001);
        $this->assertEqualsWithDelta(19.8, (float) $create->json('data.lines.0.line_total'), 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $create->json('data.subtotal'), 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $create->json('data.discount_total'), 0.001);
        $this->assertEqualsWithDelta(1.8, (float) $create->json('data.tax_total'), 0.001);
        $this->assertEqualsWithDelta(19.8, (float) $create->json('data.grand_total'), 0.001);
        $this->assertEqualsWithDelta(19.8, (float) $create->json('data.net_to_pay'), 0.001);
    }

    public function test_exchange_rate_is_one_for_primary_and_pair_snapshot_otherwise(): void
    {
        $primary = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload())
            ->assertCreated();

        $this->assertEqualsWithDelta(1.0, (float) $primary->json('data.exchange_rate'), 0.000001);

        $fx = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'currency_id' => $this->catalog['eur_id'],
            ]))
            ->assertCreated();

        $this->assertEqualsWithDelta(3.5, (float) $fx->json('data.exchange_rate'), 0.000001);

        $override = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'currency_id' => $this->catalog['eur_id'],
                'exchange_rate' => 4.25,
            ]))
            ->assertCreated();

        $this->assertEqualsWithDelta(4.25, (float) $override->json('data.exchange_rate'), 0.000001);
    }

    public function test_post_writes_customer_ledger_debit_with_uuid_reference(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 50,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->tenant->run(function () use ($id): void {
            $invoice = SalesInvoice::query()->findOrFail($id);
            $entry = CustomerLedgerEntry::query()
                ->where('customer_id', $invoice->customer_id)
                ->where('reference_type', LedgerReferenceType::Invoice)
                ->where('reference_id', $id)
                ->first();

            $this->assertNotNull($entry);
            $this->assertIsString($entry->reference_id);
            $this->assertSame($id, $entry->reference_id);
            $this->assertEqualsWithDelta(55.0, (float) $entry->debit, 0.0001);
            $this->assertEqualsWithDelta(0.0, (float) $entry->credit, 0.0001);
        });
    }

    public function test_post_issues_sale_stock_from_line_warehouse(): void
    {
        $this->tenant->run(function (): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['stock_item_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '10',
                unitCost: '5',
                itemUomId: null,
                notes: 'test opening',
                userId: null,
            ));
        });

        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 3,
                    'unit_price' => 12,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($id): void {
            $movement = StockMovement::query()
                ->where('reference_type', 'sales_invoice')
                ->where('reference_id', $id)
                ->where('item_id', $this->catalog['stock_item_id'])
                ->first();

            $this->assertNotNull($movement);
            $this->assertSame(StockMovementType::Sale, $movement->type);
            $this->assertSame('-3.000000', number_format((float) $movement->quantity_delta, 6, '.', ''));
            $this->assertSame($this->catalog['warehouse_id'], (int) $movement->warehouse_id);
        });
    }

    public function test_post_issues_bundle_components_as_bundle_sale(): void
    {
        $this->tenant->run(function (): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['bundle_child_id'],
                warehouseId: $this->catalog['warehouse_id'],
                quantityDelta: '20',
                unitCost: '2',
                itemUomId: null,
                notes: 'bundle child opening',
                userId: null,
            ));
        });

        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['bundle_item_id'],
                    'quantity' => 2,
                    'unit_price' => 30,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function () use ($id): void {
            $this->assertFalse(
                StockMovement::query()
                    ->where('reference_id', $id)
                    ->where('item_id', $this->catalog['bundle_item_id'])
                    ->exists()
            );

            $movement = StockMovement::query()
                ->where('reference_type', 'sales_invoice')
                ->where('reference_id', $id)
                ->where('item_id', $this->catalog['bundle_child_id'])
                ->first();

            $this->assertNotNull($movement);
            $this->assertSame(StockMovementType::BundleSale, $movement->type);
            $this->assertSame('-4.000000', number_format((float) $movement->quantity_delta, 6, '.', ''));
        });
    }

    public function test_post_rejects_insufficient_stock(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_price' => 12,
                    'warehouse_id' => $this->catalog['warehouse_id'],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'STOCK_INSUFFICIENT');
    }

    public function test_posted_invoice_is_locked(): void
    {
        $id = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$id}/post"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-invoices/{$id}"), ['notes' => 'locked'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_DRAFT');

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl("/sales-invoices/{$id}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NOT_DRAFT');
    }

    public function test_adjustment_cannot_make_grand_total_negative(): void
    {
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'adjustment' => -100,
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_INVOICE_NEGATIVE_TOTAL');
    }

    public function test_due_on_defaults_from_payment_terms(): void
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), $this->baseInvoicePayload([
                'invoice_date' => '2026-09-01',
                'payment_terms_id' => $this->catalog['payment_term_id'],
            ]))
            ->assertCreated();

        $this->assertSame('2026-09-11', $created->json('data.due_on'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function baseInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'currency_id' => $this->catalog['usd_id'],
            'invoice_date' => '2026-09-01',
        ], $overrides);
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

        CurrencyPairRate::query()->create([
            'from_currency_id' => $eur->id,
            'to_currency_id' => $usd->id,
            'rate' => 3.5,
            'effective_from' => now(),
        ]);

        $warehouse = Warehouse::query()->create([
            'name' => 'Sales WH',
            'shortcut_name' => 'SWH',
            'type' => WarehouseType::Central,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => true,
            'is_default_production' => false,
            'is_default_purchase' => false,
            'is_default_storage' => false,
        ]);

        $vat = VatGroup::query()->create([
            'abrv' => 'VAT10',
            'name' => 'VAT 10',
            'percentage' => 10,
            'is_default' => true,
            'is_active' => true,
        ]);

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $serviceType = ItemType::query()->where('code', 'SERVICE')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();
        $bundleType = ItemType::query()->where('code', 'BUNDLE')->firstOrFail();

        $service = $this->makeItem('Service item', 'SVC-1', $serviceType->id, $unitGroup->id, $uom->id, $vat->id, false, (int) $usd->id);
        $stock = $this->makeItem('Stock item', 'STK-1', $inventoryType->id, $unitGroup->id, $uom->id, $vat->id, true, (int) $usd->id);
        $child = $this->makeItem('Bundle child', 'BND-C', $inventoryType->id, $unitGroup->id, $uom->id, $vat->id, true, (int) $usd->id);
        $bundle = $this->makeItem('Bundle parent', 'BND-P', $bundleType->id, $unitGroup->id, $uom->id, $vat->id, false, (int) $usd->id);

        BundleItem::query()->create([
            'bundle_item_id' => $bundle->id,
            'child_item_id' => $child->id,
            'quantity' => 2,
        ]);

        $customer = Customer::query()->create([
            'name' => 'Invoice Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);

        $term = PaymentTerm::query()->create([
            'code' => 'NET10',
            'name' => 'Net 10',
            'due_days' => 10,
            'is_default' => false,
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
            'bundle_item_id' => (string) $bundle->id,
            'bundle_child_id' => (string) $child->id,
            'payment_term_id' => (int) $term->id,
        ];
    }

    private function makeItem(
        string $name,
        string $code,
        int $typeId,
        int $unitGroupId,
        int $uomId,
        int $vatId,
        bool $trackInventory,
        int $currencyId,
    ): Item {
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
            'currency_id' => $currencyId,
            'conversion_factor' => 1,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
        ]);

        return $item;
    }
}
