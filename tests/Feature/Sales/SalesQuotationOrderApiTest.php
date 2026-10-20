<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=SalesQuotationOrderApiTest
 */
#[Group('tenant-db')]
class SalesQuotationOrderApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('sales_quote_order_v1');
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

    public function test_quotation_converts_to_an_order_that_is_invoiced_in_parts(): void
    {
        $quote = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-quotations'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $this->catalog['usd_id'],
                'quotation_date' => now()->toDateString(),
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 5,
                    'unit_price' => 12,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $quoteId = (string) $quote->json('data.id');
        $this->assertMatchesRegularExpression('/^QT-\d{6}$/', (string) $quote->json('data.quotation_number'));
        $lineTotal = (string) $quote->json('data.lines.0.line_total');

        $sent = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-quotations/{$quoteId}/send"))
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');
        $this->assertNotNull($sent->json('data.sent_at'));
        $this->assertNotNull($sent->json('data.sent_by.id'));

        $accepted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-quotations/{$quoteId}/accept"))
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');
        $this->assertNotNull($accepted->json('data.accepted_at'));
        $this->assertNotNull($accepted->json('data.accepted_by.id'));
        $this->assertNotNull($accepted->json('data.sent_at'));

        $converted = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-quotations/{$quoteId}/convert"))
            ->assertOk()
            ->assertJsonPath('data.status', 'converted');

        $orderId = (string) $converted->json('data.sales_order_id');
        $this->assertNotSame('', $orderId);

        $order = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-orders/{$orderId}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.customer_id', $this->catalog['customer_id'])
            ->assertJsonPath('data.currency_id', $this->catalog['usd_id']);

        $this->assertMatchesRegularExpression('/^SO-\d{6}$/', (string) $order->json('data.order_number'));
        $this->assertSame($lineTotal, (string) $order->json('data.lines.0.line_total'));
        $this->assertEqualsWithDelta(5.0, (float) $order->json('data.lines.0.quantity'), 0.0001);

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/sales-quotations/{$quoteId}"), ['notes' => 'changed'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_QUOTATION_NOT_DRAFT');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-quotations/{$quoteId}/convert"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_QUOTATION_ALREADY_CONVERTED');

        $journalsBefore = $this->tenant->run(fn (): int => JournalEntry::query()->count());
        $movementsBefore = $this->tenant->run(fn (): int => StockMovement::query()->count());

        $confirmed = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/confirm"))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed');
        $this->assertNotNull($confirmed->json('data.confirmed_at'));
        $this->assertNotNull($confirmed->json('data.confirmed_by.id'));

        $this->tenant->run(function () use ($journalsBefore, $movementsBefore): void {
            $this->assertSame($journalsBefore, JournalEntry::query()->count());
            $this->assertSame($movementsBefore, StockMovement::query()->count());
        });

        $lineId = (int) $order->json('data.lines.0.id');
        $firstInvoiceId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/invoice"), [
                'lines' => [['sales_order_line_id' => $lineId, 'quantity' => 2]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.sales_order_id', $orderId)
            ->assertJsonPath('data.status', 'draft')
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$firstInvoiceId}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $this->tenant->run(function () use ($lineId): void {
            $line = SalesOrderLine::query()->findOrFail($lineId);
            $this->assertSame('2.000000', number_format((float) $line->invoiced_base_quantity, 6, '.', ''));
            $this->assertSame('partially_invoiced', $line->salesOrder->status->value);
            $balance = StockBalance::query()
                ->where('item_id', $this->catalog['stock_item_id'])
                ->where('warehouse_id', $this->catalog['warehouse_id'])
                ->sum('quantity');
            $this->assertEqualsWithDelta(8.0, (float) $balance, 0.0001);
        });

        $secondInvoiceId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/invoice"))
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$secondInvoiceId}/post"))
            ->assertOk();

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/sales-orders/{$orderId}"))
            ->assertOk()
            ->assertJsonPath('data.status', 'invoiced');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/invoice"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_ORDER_NOT_CONFIRMED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/cancel"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_ORDER_HAS_INVOICES');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$firstInvoiceId}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');

        $this->tenant->run(function () use ($lineId): void {
            $line = SalesOrderLine::query()->findOrFail($lineId);
            $this->assertSame('3.000000', number_format((float) $line->invoiced_base_quantity, 6, '.', ''));
            $this->assertSame('partially_invoiced', $line->salesOrder->status->value);
        });
    }

    public function test_cancel_is_allowed_before_any_posted_quantity_and_a_direct_invoice_still_posts(): void
    {
        $orderId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-orders'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 10,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/confirm"))
            ->assertOk();

        $cancelled = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-orders/{$orderId}/cancel"))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->assertNotNull($cancelled->json('data.cancelled_at'));
        $this->assertNotNull($cancelled->json('data.cancelled_by.id'));
        $this->assertNotNull($cancelled->json('data.confirmed_at'));

        $invoiceId = (string) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/sales-invoices'), [
                'customer_id' => $this->catalog['customer_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'lines' => [[
                    'item_id' => $this->catalog['service_item_id'],
                    'quantity' => 1,
                    'unit_price' => 20,
                ]],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/sales-invoices/{$invoiceId}/post"))
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.sales_order_id', null);
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

        $service = $this->makeItem('Service item', 'SVC-1', $serviceType->id, $unitGroup->id, $uom->id, $vat->id, false);
        $stock = $this->makeItem('Stock item', 'STK-1', $inventoryType->id, $unitGroup->id, $uom->id, $vat->id, true);

        app(StockMovementService::class)->post(StockMovementData::forOpening(
            itemId: (string) $stock->id,
            warehouseId: (int) $warehouse->id,
            quantityDelta: '10',
            unitCost: '5',
            itemUomId: null,
            notes: 'opening',
            userId: null,
        ));

        $customer = Customer::query()->create([
            'name' => 'Quote Customer',
            'type' => 'individual',
            'status' => 'active',
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'warehouse_id' => (int) $warehouse->id,
            'customer_id' => (string) $customer->id,
            'service_item_id' => (string) $service->id,
            'stock_item_id' => (string) $stock->id,
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
            'conversion_factor' => 1,
            'selling_price' => 10,
            'cost_price' => 5,
            'is_base' => true,
            'is_default_sale' => true,
        ]);

        return $item;
    }
}
