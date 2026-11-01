<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\ChartOfAccountsService;
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
use App\Modules\Inventory\Stock\Services\StockReservationService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Optional delivery step: sales order -> delivery note (stock + cost) -> invoice (revenue only).
 */
#[Group('tenant-db')]
class DeliveryNoteApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private const COGS = '6053';

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('delivery_note_v1');
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

    public function test_order_delivery_then_invoice_books_cost_once(): void
    {
        $order = $this->confirmedOrder(6);

        $note = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/deliver"))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.lines.0.quantity', '6.000000')
            ->json('data');

        $posted = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/post"))->assertOk();
        $this->assertMatchesRegularExpression('/^DN-[A-Z0-9]+-\d{4}-000001$/', (string) $posted->json('data.delivery_number'));

        $this->assertSame('4.000000', $this->onHand());
        $this->assertSame('0.000000', $this->reserved());
        $this->assertSame('30.0000', $this->cogs('delivery_note', $note['id']));

        $invoice = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/invoice"))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.delivery_note_line_id', $note['lines'][0]['id'])
            ->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice}/post"))->assertOk();

        // Revenue only: no stock movement and no cost on the invoice.
        $this->assertSame('4.000000', $this->onHand());
        $this->assertSame(0, $this->tenant->run(fn () => StockMovement::query()->where('reference_type', 'sales_invoice')->where('reference_id', $invoice)->count()));
        $this->assertSame('0.0000', $this->cogs('sales_invoice', $invoice));

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/delivery-notes/{$note['id']}"))
            ->assertJsonPath('data.invoicing_status', 'invoiced');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/reverse"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DELIVERY_NOTE_HAS_INVOICES');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/invoice"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DELIVERY_NOTE_NOTHING_TO_INVOICE');
    }

    public function test_partial_delivery_direct_invoice_of_the_rest_and_reversal(): void
    {
        $order = $this->confirmedOrder(6);
        $note = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/deliver"))->assertCreated()->json('data');

        // Deliver only 4 of 6.
        $this->asTenantRequest($this->token)->putJson($this->tenantUrl("/delivery-notes/{$note['id']}"), [
            'lines' => [[
                'sales_order_line_id' => $note['lines'][0]['sales_order_line_id'],
                'item_id' => $this->catalog['stock_item_id'],
                'item_uom_id' => $note['lines'][0]['item_uom_id'],
                'quantity' => 4,
            ]],
        ])->assertOk();
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/post"))->assertOk();
        $this->assertSame('2.000000', $this->reserved());

        // A direct invoice of the order offers only the 2 not shipped yet and ships them.
        $direct = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/invoice"))
            ->assertCreated()
            ->assertJsonPath('data.lines.0.quantity', '2.000000')
            ->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$direct}/post"))->assertOk();
        $this->assertSame('4.000000', $this->onHand());
        $this->assertSame('0.000000', $this->reserved());
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/deliver"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_ORDER_NOTHING_TO_DELIVER');

        // Reversing the (uninvoiced) delivery note puts stock and reservation back.
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/reverse"))
            ->assertOk()
            ->assertJsonPath('data.status', 'reversed');
        $this->assertSame('8.000000', $this->onHand());
        $this->assertSame('4.000000', $this->reserved());
        $this->assertSame('0.0000', $this->cogs('delivery_note', $note['id']));

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/cancel"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'SALES_ORDER_HAS_INVOICES');
    }

    public function test_credit_note_restock_uses_the_delivery_note_cost(): void
    {
        $order = $this->confirmedOrder(2);
        $note = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/deliver"))->assertCreated()->json('data');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/post"))->assertOk();
        $invoice = $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$note['id']}/invoice"))->assertCreated()->json('data');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-invoices/{$invoice['id']}/post"))->assertOk();

        $credit = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/sales-credit-notes'), [
            'sales_invoice_id' => $invoice['id'],
            'type' => 'return',
            'credit_date' => now()->toDateString(),
            'reason' => 'Return one',
            'lines' => [[
                'sales_invoice_line_id' => $invoice['lines'][0]['id'],
                'quantity' => 1,
                'warehouse_id' => $this->catalog['warehouse_id'],
                'restock' => true,
            ]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-credit-notes/{$credit}/post"))->assertOk();

        $value = $this->tenant->run(fn () => (string) StockMovement::query()
            ->where('reference_type', 'sales_credit_note')->where('reference_id', $credit)->value('value_delta'));
        $this->assertSame('5.0000', $value);
    }

    public function test_standalone_delivery_note_and_draft_rules(): void
    {
        $id = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/delivery-notes'), [
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'lines' => [['item_id' => $this->catalog['stock_item_id'], 'quantity' => 3]],
        ])->assertCreated()->assertJsonPath('data.delivery_number', null)->json('data.id');

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/delivery-notes/{$id}/pdf"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DELIVERY_NOTE_PDF_NOT_ALLOWED');

        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$id}/post"))->assertOk();
        $this->assertSame('7.000000', $this->onHand());

        $pdf = $this->asTenantRequest($this->token)->get($this->tenantUrl("/delivery-notes/{$id}/pdf"));
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl("/delivery-notes/{$id}"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'DELIVERY_NOTE_NOT_DRAFT');
    }

    public function test_order_template_fills_the_drawer_without_saving_and_order_must_match_customer(): void
    {
        $order = $this->confirmedOrder(4);

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/delivery-notes/deliverable-orders?customer_id='.$this->catalog['customer_id']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $order);

        $template = $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/sales-orders/{$order}/delivery-template"))
            ->assertOk()
            ->assertJsonPath('data.sales_order_id', $order)
            ->assertJsonPath('data.lines.0.quantity', '4.000000')
            ->assertJsonPath('data.lines.0.item.item_code', 'STK-DN')
            ->json('data');
        $this->assertSame(0, $this->tenant->run(fn (): int => DeliveryNote::query()->count()));

        // Saving the filled drawer creates the draft; posting ships it.
        $id = $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/delivery-notes'), [
            'customer_id' => $template['customer_id'],
            'warehouse_id' => $template['warehouse_id'],
            'sales_order_id' => $order,
            'delivery_date' => $template['delivery_date'],
            'lines' => array_map(fn (array $l): array => [
                'sales_order_line_id' => $l['sales_order_line_id'], 'item_id' => $l['item_id'], 'item_uom_id' => $l['item_uom_id'], 'quantity' => $l['quantity'],
            ], $template['lines']),
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/delivery-notes/{$id}/post"))->assertOk();
        $this->assertSame('6.000000', $this->onHand());

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/delivery-notes/deliverable-orders?customer_id='.$this->catalog['customer_id']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $other = $this->tenant->run(fn (): string => (string) Customer::query()->create(['name' => 'Other', 'type' => 'individual', 'status' => 'active'])->id);
        $next = $this->confirmedOrder(1);
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/delivery-notes'), [
            'customer_id' => $other,
            'warehouse_id' => $this->catalog['warehouse_id'],
            'sales_order_id' => $next,
            'lines' => [['item_id' => $this->catalog['stock_item_id'], 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonPath('code', 'DELIVERY_NOTE_ORDER_CUSTOMER_MISMATCH');
    }

    private function confirmedOrder(int $qty): string
    {
        $order = (string) $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/sales-orders'), [
            'customer_id' => $this->catalog['customer_id'],
            'warehouse_id' => $this->catalog['warehouse_id'],
            'lines' => [['item_id' => $this->catalog['stock_item_id'], 'quantity' => $qty, 'unit_price' => 20]],
        ])->assertCreated()->json('data.id');
        $this->asTenantRequest($this->token)->postJson($this->tenantUrl("/sales-orders/{$order}/confirm"))->assertOk();

        return $order;
    }

    private function onHand(): string
    {
        return $this->tenant->run(fn (): string => StockBalance::onHandForWarehouse($this->catalog['stock_item_id'], (int) $this->catalog['warehouse_id']));
    }

    private function reserved(): string
    {
        return $this->tenant->run(fn (): string => app(StockReservationService::class)
            ->reservedFor($this->catalog['stock_item_id'], (int) $this->catalog['warehouse_id']));
    }

    /** Net cost of goods sold booked by a document's journals (post minus reversal). */
    private function cogs(string $sourceType, string $sourceId): string
    {
        return $this->tenant->run(function () use ($sourceType, $sourceId): string {
            $entryIds = JournalEntry::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->pluck('id');
            $row = JournalEntryLine::query()
                ->whereIn('journal_entry_id', $entryIds)
                ->where('account_id', Account::query()->where('code', self::COGS)->value('id'))
                ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as net')
                ->first();

            return bcadd((string) ($row?->getAttribute('net') ?? '0'), '0', 4);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create(['name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true]);
        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => false,
            'allow_negative_stock' => false,
            'price_decimal_places' => 2,
        ]);
        app(CompanySettingService::class)->forgetCache();
        app(ChartOfAccountsService::class)->seedFromTemplate();

        $warehouse = Warehouse::query()->create([
            'name' => 'Main WH', 'shortcut_name' => 'MWH', 'type' => WarehouseType::Central, 'manager_id' => $this->tenantUser->id,
            'is_active' => true, 'is_default' => true, 'is_default_sales' => true, 'is_default_production' => false,
            'is_default_purchase' => true, 'is_default_storage' => false,
        ]);

        $item = Item::query()->create([
            'name' => 'Stock item', 'item_code' => 'STK-DN',
            'item_type_id' => ItemType::query()->where('code', 'INVENTORY')->value('id'),
            'unit_group_id' => UnitGroup::query()->where('code', 'COUNT')->value('id'),
            'base_uom_id' => UnitOfMeasurement::query()->where('code', 'EA')->value('id'),
            'track_inventory' => true, 'track_lots' => false, 'allow_sale' => true, 'allow_purchase' => true, 'is_active' => true,
        ]);
        ItemUom::query()->create([
            'item_id' => $item->id, 'uom_id' => UnitOfMeasurement::query()->where('code', 'EA')->value('id'),
            'conversion_factor' => 1, 'selling_price' => 20, 'cost_price' => 5, 'is_base' => true, 'is_default_sale' => true,
        ]);

        app(StockMovementService::class)->post(StockMovementData::forOpening(
            itemId: (string) $item->id,
            warehouseId: (int) $warehouse->id,
            quantityDelta: '10',
            unitCost: '5',
            itemUomId: null,
            notes: 'opening',
            userId: null,
        ));

        $customer = Customer::query()->create(['name' => 'Delivery Customer', 'type' => 'individual', 'status' => 'active']);

        return [
            'usd_id' => (int) $usd->id,
            'warehouse_id' => (int) $warehouse->id,
            'stock_item_id' => (string) $item->id,
            'customer_id' => (string) $customer->id,
        ];
    }
}
