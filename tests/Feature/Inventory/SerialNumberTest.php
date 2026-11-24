<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\InventorySerial;
use App\Modules\Inventory\Stock\Services\InventorySerialService;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\DocumentPdf\DocumentMoney;
use App\Support\DocumentPdf\DocumentPdfSupport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Serial numbers through the single stock write path (StockMovementService):
 * receive, sell, refuse unavailable units, reverse, and block reversing a unit that moved on.
 */
class SerialNumberTest extends TestCase
{
    use InteractsWithTenant;

    private string $itemId;

    private int $warehouseId;

    private int $otherWarehouseId;

    private string $customerId;

    /** @var array<string, string> fake document ids by label */
    private array $refs = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->tenant->run(function (): void {
            $group = UnitGroup::query()->create([
                'code' => 'CNT',
                'name' => 'Count',
                'dimension_type' => 'count',
                'is_active' => true,
            ]);
            $item = Item::query()->create([
                'name' => 'Phone X',
                'item_code' => 'PHX',
                'item_type_id' => (int) DB::table('item_types')->orderBy('id')->value('id'),
                'unit_group_id' => $group->id,
                'track_inventory' => true,
                'track_serials' => true,
                'warranty_months' => 12,
                'allow_sale' => true,
                'allow_purchase' => true,
                'is_active' => true,
            ]);
            $this->itemId = (string) $item->id;
            $this->warehouseId = (int) Warehouse::query()->create([
                'name' => 'Main', 'shortcut_name' => 'MAIN', 'type' => 'central',
                'manager_id' => $this->tenantUser->id, 'is_active' => true,
            ])->id;
            $this->otherWarehouseId = (int) Warehouse::query()->create([
                'name' => 'Shop', 'shortcut_name' => 'SHOP', 'type' => 'central',
                'manager_id' => $this->tenantUser->id, 'is_active' => true,
            ])->id;
            $this->customerId = (string) $this->createCustomer('Serial Buyer')->id;
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_normalize_trims_uppercases_and_refuses_duplicates(): void
    {
        $this->assertSame(['AB1', 'CD2'], InventorySerialService::normalize([' ab1 ', '', 'cd2']));
        $this->assertSame(['AB1', 'CD2'], InventorySerialService::normalize("ab1\ncd2"));

        $this->assertErrorCode('STOCK_SERIAL_DUPLICATE', fn () => InventorySerialService::normalize(['ab1', 'AB1']));
    }

    public function test_receive_then_sell_tracks_status_customer_and_warranty(): void
    {
        $this->tenant->run(function (): void {
            $this->receive(['sn-1', 'SN-2', 'SN-3'], 'grn-1');

            $this->assertSame(3, InventorySerial::query()->where('status', InventorySerial::STATUS_IN_STOCK)->count());

            $this->sell(['SN-1', 'SN-2'], 'si-1');

            $sold = InventorySerial::query()->where('serial_number', 'SN-1')->firstOrFail();
            $this->assertSame(InventorySerial::STATUS_SOLD, $sold->status);
            $this->assertSame($this->customerId, (string) $sold->customer_id);
            $this->assertNotNull($sold->warranty_ends_on);
            $this->assertSame(
                InventorySerial::STATUS_IN_STOCK,
                InventorySerial::query()->where('serial_number', 'SN-3')->value('status'),
            );
            $this->assertSame(2, (int) $sold->movements()->count());
        });
    }

    public function test_count_must_match_quantity_and_units_must_be_available(): void
    {
        $this->tenant->run(function (): void {
            $this->assertErrorCode('STOCK_SERIAL_COUNT_MISMATCH', fn () => $this->receive(['A1'], 'grn-1', '2'));

            $this->receive(['A1', 'A2'], 'grn-2');
            $this->assertErrorCode('STOCK_SERIAL_ALREADY_IN_STOCK', fn () => $this->receive(['A2'], 'grn-3'));

            $this->sell(['A1'], 'si-1');
            $this->assertErrorCode('STOCK_SERIAL_NOT_AVAILABLE', fn () => $this->sell(['A1'], 'si-2'));
            $this->assertErrorCode('STOCK_SERIAL_NOT_AVAILABLE', fn () => $this->sell(['NOPE'], 'si-3'));
            $this->assertErrorCode(
                'STOCK_SERIAL_NOT_AVAILABLE',
                fn () => $this->sell(['A2'], 'si-4', $this->otherWarehouseId),
            );
        });
    }

    public function test_reversing_a_sale_puts_units_back_and_a_moved_unit_blocks_reversing_its_receipt(): void
    {
        $this->tenant->run(function (): void {
            $this->receive(['R1', 'R2'], 'grn-1');
            $this->sell(['R1'], 'si-1');

            // The receipt can't be undone while R1 is with the customer.
            $this->assertErrorCode('STOCK_SERIAL_MOVED_SINCE', fn () => DB::transaction(
                fn () => app(StockMovementService::class)->reverseReference('goods_receipt', $this->ref('grn-1'), null),
            ));

            DB::transaction(fn () => app(StockMovementService::class)->reverseReference('sales_invoice', $this->ref('si-1'), null));
            $r1 = InventorySerial::query()->where('serial_number', 'R1')->firstOrFail();
            $this->assertSame(InventorySerial::STATUS_IN_STOCK, $r1->status);
            $this->assertNull($r1->customer_id);
            $this->assertNull($r1->warranty_ends_on);

            DB::transaction(fn () => app(StockMovementService::class)->reverseReference('goods_receipt', $this->ref('grn-1'), null));
            $this->assertSame(2, InventorySerial::query()->where('status', InventorySerial::STATUS_VOID)->count());

            // A voided number can be received again.
            $this->receive(['R1'], 'grn-2');
            $this->assertSame(
                InventorySerial::STATUS_IN_STOCK,
                InventorySerial::query()->where('serial_number', 'R1')->value('status'),
            );
        });
    }

    public function test_a_restocked_return_clears_the_sale_and_reversing_it_restores_the_sale(): void
    {
        $this->tenant->run(function (): void {
            $this->receive(['RT1'], 'grn-rt');
            $this->sell(['RT1'], 'si-rt');

            app(StockMovementService::class)->post(StockMovementData::forSalesReturn(
                itemId: $this->itemId,
                warehouseId: $this->warehouseId,
                quantityDelta: '1',
                salesCreditNoteId: $this->ref('scn-rt'),
                unitCost: '100',
                itemUomId: null,
                notes: null,
                userId: null,
            )->withSerials(['RT1'], 'customer', $this->customerId));

            $unit = InventorySerial::query()->where('serial_number', 'RT1')->firstOrFail();
            $this->assertSame(InventorySerial::STATUS_IN_STOCK, $unit->status);
            $this->assertNull($unit->customer_id);
            $this->assertNull($unit->sold_ref_id);
            $this->assertNull($unit->warranty_ends_on);
            $this->assertSame(3, (int) $unit->movements()->count());

            DB::transaction(fn () => app(StockMovementService::class)->reverseReference('sales_credit_note', $this->ref('scn-rt'), null));
            $unit->refresh();
            $this->assertSame(InventorySerial::STATUS_SOLD, $unit->status);
            $this->assertSame($this->customerId, (string) $unit->customer_id);
            $this->assertSame($this->ref('si-rt'), (string) $unit->sold_ref_id);
            $this->assertNotNull($unit->warranty_ends_on);
        });
    }

    public function test_transfer_ships_in_transit_receives_part_and_writes_off_the_rest(): void
    {
        $this->tenant->run(function (): void {
            $this->receive(['T1', 'T2', 'T3'], 'grn-1');
            $transfer = $this->ref('st-1');
            $this->transfer(['T1', 'T2', 'T3'], $transfer, StockMovementType::TransferOut, $this->warehouseId, '-3');

            $this->assertSame(3, InventorySerial::query()->where('status', InventorySerial::STATUS_IN_TRANSIT)->count());
            // A unit on its way can't be sold.
            $this->assertErrorCode('STOCK_SERIAL_NOT_AVAILABLE', fn () => $this->sell(['T1'], 'si-1'));

            $service = app(InventorySerialService::class);
            $item = Item::query()->findOrFail($this->itemId);
            // Receiving the whole remaining quantity without naming serials takes the units in transit.
            $this->assertSame(['T1', 'T2', 'T3'], $service->pickFromTransit($item, ['T1', 'T2', 'T3'], null, '3.000000'));
            $this->assertErrorCode('STOCK_SERIAL_COUNT_MISMATCH', fn () => $service->pickFromTransit($item, ['T1', 'T2', 'T3'], null, '2.000000'));
            $this->assertErrorCode('STOCK_SERIAL_NOT_ON_SOURCE', fn () => $service->pickFromTransit($item, ['T1', 'T2', 'T3'], ['T1', 'X9'], '2.000000'));

            $this->transfer(['T1', 'T2'], $transfer, StockMovementType::TransferIn, $this->otherWarehouseId, '2');
            $t1 = InventorySerial::query()->where('serial_number', 'T1')->firstOrFail();
            $this->assertSame(InventorySerial::STATUS_IN_STOCK, $t1->status);
            $this->assertSame($this->otherWarehouseId, (int) $t1->warehouse_id);

            // Only units in transit can come in.
            $this->assertErrorCode('STOCK_SERIAL_NOT_IN_TRANSIT', fn () => $this->transfer(['T1'], $transfer, StockMovementType::TransferIn, $this->otherWarehouseId, '1'));

            $this->assertSame(['T3'], $service->stillInTransit($this->itemId, ['T1', 'T2', 'T3']));
            $service->writeOffInTransit($item, ['T3'], 'stock_transfer_closure', $this->ref('closure-1'), null);
            $this->assertSame(
                InventorySerial::STATUS_SCRAPPED,
                InventorySerial::query()->where('serial_number', 'T3')->value('status'),
            );
        });
    }

    public function test_adjustment_out_writes_units_off_and_its_reversal_brings_them_back(): void
    {
        $this->tenant->run(function (): void {
            $this->receive(['A1', 'A2'], 'grn-1');
            $adjustment = $this->ref('adj-1');
            app(StockMovementService::class)->post(StockMovementData::forAdjustment(
                itemId: $this->itemId,
                warehouseId: $this->warehouseId,
                quantityDelta: '-1',
                unitCost: null,
                itemUomId: null,
                notes: null,
                userId: null,
                stockAdjustmentId: $adjustment,
            )->withSerials(['A1']));
            $this->assertSame(InventorySerial::STATUS_SCRAPPED, InventorySerial::query()->where('serial_number', 'A1')->value('status'));

            DB::transaction(fn () => app(StockMovementService::class)->reverseReference('stock_adjustment', $adjustment, null));
            $this->assertSame(InventorySerial::STATUS_IN_STOCK, InventorySerial::query()->where('serial_number', 'A1')->value('status'));
        });
    }

    public function test_an_item_without_serial_tracking_refuses_serials(): void
    {
        $this->tenant->run(function (): void {
            Item::query()->whereKey($this->itemId)->update(['track_serials' => false]);
            $this->assertErrorCode('STOCK_SERIAL_NOT_TRACKED', fn () => $this->receive(['X1'], 'grn-1'));
        });
    }

    /**
     * @param  list<string>  $serials
     */
    public function test_serial_reports_list_stock_sales_and_warranties(): void
    {
        $this->tenant->run(function (): void {
            $this->receive(['R-1', 'R-2', 'R-3'], 'grn-r');
            $this->sell(['R-1'], 'si-r');
            InventorySerial::query()->where('serial_number', 'R-1')->update(['warranty_ends_on' => now()->addDays(10)->toDateString()]);
        });
        $token = $this->tenantBearerToken();

        $stock = $this->asTenantRequest($token)->getJson($this->tenantUrl('reports/serials-in-stock?per_page=20'))->assertOk()->json('data');
        $this->assertSame(['R-2', 'R-3'], array_column($stock['rows'], 'serial'));
        $this->assertSame('Main', $stock['rows'][0]['warehouse']);
        $this->assertSame(2, $stock['pagination']['total']);

        $sold = $this->asTenantRequest($token)->getJson($this->tenantUrl('reports/serials-sold?per_page=20'))->assertOk()->json('data');
        $this->assertSame(['R-1'], array_column($sold['rows'], 'serial'));
        $this->assertSame('Serial Buyer', $sold['rows'][0]['customer']);

        $soon = $this->asTenantRequest($token)->getJson($this->tenantUrl('reports/warranty-expiring?days=30&per_page=20'))->assertOk()->json('data');
        $this->assertSame(['R-1'], array_column($soon['rows'], 'serial'));
        $this->assertSame(10, $soon['rows'][0]['days_left']);
        $later = $this->asTenantRequest($token)->getJson($this->tenantUrl('reports/warranty-expiring?days=5&per_page=20'))->assertOk()->json('data');
        $this->assertSame([], $later['rows']);

        $search = $this->asTenantRequest($token)->getJson($this->tenantUrl('reports/serials-in-stock?per_page=20&search=r-3'))->assertOk()->json('data');
        $this->assertSame(['R-3'], array_column($search['rows'], 'serial'));
    }

    public function test_document_lines_print_their_serial_numbers(): void
    {
        $line = (object) ['serial_numbers' => ['SN-1', 'SN-2']];
        $this->assertSame('SN-1, SN-2', DocumentPdfSupport::serialText($line));
        $this->assertSame('', DocumentPdfSupport::serialText((object) ['serial_numbers' => null]));
        $this->assertSame('', DocumentPdfSupport::serialText((object) []));

        $this->tenant->run(function (): void {
            $money = new DocumentMoney(false);
            $rows = DocumentPdfSupport::quantityLines([(object) ['quantity' => '2', 'serial_numbers' => ['A1', 'B2']]], $money);
            $this->assertSame('A1, B2', $rows[0]['serials']);

            $html = view('document-pdf.partials.lines', [
                'lines' => $rows,
                'lineColumns' => ['description', 'qty'],
                'labels' => ['description' => 'Description', 'qty' => 'Qty', 'serials' => 'Serial numbers'],
            ])->render();
            $this->assertStringContainsString('Serial numbers: <span dir="ltr">A1, B2</span>', $html);
        });
    }

    private function receive(array $serials, string $ref, ?string $quantity = null): void
    {
        app(StockMovementService::class)->post(StockMovementData::forPurchase(
            itemId: $this->itemId,
            warehouseId: $this->warehouseId,
            quantityDelta: $quantity ?? (string) count($serials),
            purchaseOrderId: null,
            unitCost: '100',
            itemUomId: null,
            notes: null,
            userId: null,
            goodsReceiptId: $this->ref($ref),
        )->withSerials($serials));
    }

    /**
     * @param  list<string>  $serials
     */
    private function sell(array $serials, string $ref, ?int $warehouseId = null): void
    {
        app(StockMovementService::class)->post(StockMovementData::forSale(
            itemId: $this->itemId,
            warehouseId: $warehouseId ?? $this->warehouseId,
            quantityDelta: '-'.count($serials),
            salesInvoiceId: $this->ref($ref),
            itemUomId: null,
            notes: null,
            userId: null,
        )->withSerials($serials, 'customer', $this->customerId));
    }

    /**
     * @param  list<string>  $serials
     */
    private function transfer(array $serials, string $transferId, StockMovementType $type, int $warehouseId, string $quantity): void
    {
        app(StockMovementService::class)->post(StockMovementData::forTransfer(
            itemId: $this->itemId,
            warehouseId: $warehouseId,
            quantityDelta: $quantity,
            type: $type,
            stockTransferId: $transferId,
            itemUomId: null,
            notes: null,
            userId: null,
            unitCost: '100',
        )->withSerials($serials));
    }

    private function ref(string $label): string
    {
        return $this->refs[$label] ??= (string) Str::uuid();
    }

    private function assertErrorCode(string $code, callable $callback): void
    {
        try {
            $callback();
        } catch (HttpException $e) {
            $this->assertSame($code, $e->getHeaders()['X-Error-Code'] ?? null, $e->getMessage());

            return;
        }
        $this->fail('Expected error '.$code);
    }
}
