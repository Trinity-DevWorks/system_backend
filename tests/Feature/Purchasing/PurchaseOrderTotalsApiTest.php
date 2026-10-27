<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Purchase order currency, exchange rate and stored totals (docs/printing-plan.md D1.2), and the
 * goods receipt reference to the order's currency (D1.3).
 */
#[Group('tenant-db')]
class PurchaseOrderTotalsApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('purchase_order_totals_v1');
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

    public function test_totals_use_the_shared_tax_math_and_are_stored(): void
    {
        $order = $this->createOrder([
            'currency_id' => $this->catalog['eur_id'],
            'lines' => [[
                'item_id' => $this->catalog['item_id'],
                'quantity' => 2,
                'unit_price' => 100,
                'discount_percent' => 10,
            ]],
        ]);

        $this->assertSame($this->catalog['eur_id'], (int) $order->json('data.currency_id'));
        $this->assertSame('EUR', $order->json('data.currency_code'));
        $this->assertEqualsWithDelta(3.5, (float) $order->json('data.exchange_rate'), 0.0000001);
        $this->assertMoney(200, $order->json('data.subtotal'));
        $this->assertMoney(20, $order->json('data.discount_total'));
        $this->assertMoney(19.8, $order->json('data.tax_total'));
        $this->assertMoney(199.8, $order->json('data.grand_total'));
        $this->assertMoney(199.8, $order->json('data.total_amount'));

        $this->assertMoney(10, $order->json('data.lines.0.discount_percent'));
        $this->assertMoney(20, $order->json('data.lines.0.discount_amount'));
        $this->assertMoney(11, $order->json('data.lines.0.tax_rate'));
        $this->assertMoney(180, $order->json('data.lines.0.line_subtotal'));
        $this->assertMoney(19.8, $order->json('data.lines.0.tax_amount'));
        $this->assertMoney(199.8, $order->json('data.lines.0.line_total'));

        $id = (string) $order->json('data.id');
        $this->tenant->run(function () use ($id): void {
            $row = PurchaseOrder::query()->findOrFail($id);
            $this->assertEqualsWithDelta(199.8, (float) $row->grand_total, 0.0001);
        });
    }

    public function test_purchase_order_pdf_needs_stock_print_and_a_confirmed_order(): void
    {
        $id = (string) $this->createOrder([
            'lines' => [['item_id' => $this->catalog['item_id'], 'quantity' => 1, 'unit_price' => 100]],
        ])->json('data.id');

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/stock/purchase-orders/{$id}/pdf"))
            ->assertStatus(422)
            ->assertJsonPath('code', 'PURCHASE_ORDER_PDF_NOT_ALLOWED');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/purchase-orders/{$id}/confirm"))
            ->assertOk();
        $pdf = $this->asTenantRequest($this->token)
            ->get($this->tenantUrl("/stock/purchase-orders/{$id}/pdf"))
            ->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->tenant->run(function (): void {
            $permissionId = Permission::query()->where('resource_key', 'stock')->value('id');
            RolePermission::query()->where('permission_id', $permissionId)->update(['can_print' => false]);
            app(PermissionService::class)->invalidateCacheForUser($this->tenantUser);
        });
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/stock/purchase-orders/{$id}/pdf"))
            ->assertForbidden();
        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/stock/purchase-orders/{$id}"))
            ->assertOk();
    }

    public function test_line_sync_and_header_changes_recompute_totals(): void
    {
        $id = (string) $this->createOrder([
            'lines' => [[
                'item_id' => $this->catalog['item_id'],
                'quantity' => 1,
                'unit_price' => 50,
            ]],
        ])->assertJsonPath('data.currency_code', 'USD')->json('data.id');

        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/stock/purchase-orders/{$id}/lines/sync"), [
                'lines' => [
                    ['item_id' => $this->catalog['item_id'], 'quantity' => 3, 'unit_price' => 10],
                    ['item_id' => $this->catalog['exempt_item_id'], 'quantity' => 1, 'unit_price' => 40, 'discount_percent' => 25],
                ],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $synced = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl("/stock/purchase-orders/{$id}"))
            ->assertOk();

        $this->assertMoney(70, $synced->json('data.subtotal'));
        $this->assertMoney(10, $synced->json('data.discount_total'));
        $this->assertMoney(3.3, $synced->json('data.tax_total'));
        $this->assertMoney(63.3, $synced->json('data.grand_total'));

        $updated = $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/stock/purchase-orders/{$id}"), [
                'currency_id' => $this->catalog['eur_id'],
                'exchange_rate' => 4,
            ])
            ->assertOk();

        $this->assertSame('EUR', $updated->json('data.currency_code'));
        $this->assertEqualsWithDelta(4, (float) $updated->json('data.exchange_rate'), 0.0000001);
        $this->assertMoney(63.3, $updated->json('data.grand_total'));

        $list = $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/stock/purchase-orders'))
            ->assertOk();
        $row = collect($list->json('data.data'))->firstWhere('id', $id);
        $this->assertIsArray($row);
        $this->assertSame('EUR', $row['currency_code']);
        $this->assertMoney(63.3, $row['grand_total']);
    }

    public function test_tax_exempt_supplier_orders_carry_no_vat(): void
    {
        $this->tenant->run(function (): void {
            Supplier::query()->whereKey($this->catalog['supplier_id'])->update(['is_exempted' => true]);
        });

        $order = $this->createOrder([
            'lines' => [['item_id' => $this->catalog['item_id'], 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $this->assertMoney(0, $order->json('data.tax_total'));
        $this->assertMoney(100, $order->json('data.grand_total'));
    }

    public function test_goods_receipt_references_the_order_currency_and_costs_in_primary(): void
    {
        $orderId = (string) $this->createOrder([
            'currency_id' => $this->catalog['eur_id'],
            'lines' => [[
                'item_id' => $this->catalog['item_id'],
                'quantity' => 2,
                'unit_price' => 100,
                'discount_percent' => 10,
            ]],
        ])->json('data.id');

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/stock/purchase-orders/{$orderId}/confirm"))
            ->assertOk();

        $receipt = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/goods-receipts'), [
                'purchase_order_id' => $orderId,
                'received_date' => '2026-09-02',
            ])
            ->assertCreated();

        $this->assertSame($this->catalog['eur_id'], (int) $receipt->json('data.currency_id'));
        $this->assertSame('EUR', $receipt->json('data.currency_code'));
        $this->assertEqualsWithDelta(3.5, (float) $receipt->json('data.exchange_rate'), 0.0000001);
        // Net PO price 90 EUR (after the 10% discount, before VAT) at 3.5 EUR per USD, at price precision.
        $this->assertEqualsWithDelta(25.71, (float) $receipt->json('data.lines.0.unit_cost'), 0.0001);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(array $overrides): TestResponse
    {
        return $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/stock/purchase-orders'), array_merge([
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'order_date' => '2026-09-01',
            ], $overrides))
            ->assertCreated();
    }

    private function assertMoney(float $expected, mixed $actual): void
    {
        $this->assertNotNull($actual);
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.0001);
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $usd = Currency::query()->create(['name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true]);
        $eur = Currency::query()->create(['name' => 'Euro', 'code' => 'EUR', 'iso_code' => 'EUR', 'symbol' => '€', 'is_active' => true]);

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => true,
            'tax_price_mode' => 'exclusive',
            'allow_negative_stock' => false,
        ]);
        app(CompanySettingService::class)->forgetCache();
        app(ExchangeRateService::class)->setPairRate($usd->id, $eur->id, 3.5);

        $warehouse = Warehouse::query()->create([
            'name' => 'Purchase WH',
            'shortcut_name' => 'PWH',
            'type' => WarehouseType::Central,
            'manager_id' => $this->tenantUser->id,
            'is_active' => true,
            'is_default' => true,
            'is_default_sales' => false,
            'is_default_production' => false,
            'is_default_purchase' => true,
            'is_default_storage' => false,
        ]);

        $vat = VatGroup::query()->create(['abrv' => 'VAT11', 'name' => 'VAT 11', 'percentage' => 11, 'is_default' => true, 'is_active' => true]);
        $zero = VatGroup::query()->create(['abrv' => 'VAT0', 'name' => 'VAT 0', 'percentage' => 0, 'is_default' => false, 'is_active' => true]);

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();

        $item = $this->makeItem('Taxed item', 'PO-TAX', (int) $inventoryType->id, (int) $unitGroup->id, (int) $uom->id, (int) $vat->id);
        $exempt = $this->makeItem('Zero item', 'PO-ZERO', (int) $inventoryType->id, (int) $unitGroup->id, (int) $uom->id, (int) $zero->id);

        $supplier = Supplier::query()->create(['name' => 'PO Supplier', 'is_active' => true]);

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'warehouse_id' => (int) $warehouse->id,
            'supplier_id' => (string) $supplier->id,
            'item_id' => (string) $item->id,
            'exempt_item_id' => (string) $exempt->id,
        ];
    }

    private function makeItem(string $name, string $code, int $typeId, int $unitGroupId, int $uomId, int $vatId): Item
    {
        $item = Item::query()->create([
            'name' => $name,
            'item_code' => $code,
            'item_type_id' => $typeId,
            'unit_group_id' => $unitGroupId,
            'base_uom_id' => $uomId,
            'vat_group_id' => $vatId,
            'track_inventory' => true,
            'track_lots' => false,
            'allow_sale' => true,
            'allow_purchase' => true,
            'is_active' => true,
        ]);
        ItemUom::query()->create([
            'item_id' => $item->id,
            'uom_id' => $uomId,
            'conversion_factor' => 1,
            'selling_price' => 15,
            'cost_price' => 8,
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        return $item;
    }
}
