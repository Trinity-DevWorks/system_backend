<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Models\CurrencyPairRate;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\VatGroup\Models\VatGroup;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Requires local Postgres (Stancl tenant schemas). Skipped in CI via --exclude-group=tenant-db.
 * Run with: php artisan test --group=tenant-db --filter=PurchaseInvoiceCurrencyApiTest
 */
#[Group('tenant-db')]
class PurchaseInvoiceCurrencyApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('purchase_invoice_fx_v1');
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

    public function test_foreign_invoice_snapshots_primary_to_document_rate_and_posts_primary_costs(): void
    {
        $created = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['lbp_id'],
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 2,
                    'unit_price' => 895000,
                ]],
            ])
            ->assertCreated();

        $this->assertSame('89500.000000000000', $created->json('data.exchange_rate'));

        $id = (string) $created->json('data.id');
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/purchase-invoices/{$id}/post"))
            ->assertOk();

        $this->tenant->run(function (): void {
            $movement = StockMovement::query()->where('item_id', $this->catalog['stock_item_id'])->firstOrFail();
            $this->assertEqualsWithDelta(10.0, (float) $movement->unit_cost, 0.0001);

            $link = SupplierItem::query()
                ->where('supplier_id', $this->catalog['supplier_id'])
                ->where('item_id', $this->catalog['stock_item_id'])
                ->firstOrFail();
            $this->assertEqualsWithDelta(10.0, (float) $link->last_purchase_price, 0.0001);
        });
    }

    public function test_provided_rate_keeps_twelve_decimals(): void
    {
        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/purchase-invoices'), [
                'supplier_id' => $this->catalog['supplier_id'],
                'warehouse_id' => $this->catalog['warehouse_id'],
                'currency_id' => $this->catalog['lbp_id'],
                'exchange_rate' => '0.000011173184',
                'invoice_date' => '2026-09-01',
                'lines' => [[
                    'item_id' => $this->catalog['stock_item_id'],
                    'quantity' => 1,
                    'unit_price' => 1,
                ]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.exchange_rate', '0.000011173184');
    }

    public function test_primary_currency_is_locked_once_prices_exist(): void
    {
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/currencies/'.$this->catalog['lbp_id']), ['is_primary' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CURRENCY_PRIMARY_LOCKED');

        $this->tenant->run(function (): void {
            $this->assertSame($this->catalog['usd_id'], (int) CompanySetting::singleton()->primary_currency_id);
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
        $lbp = Currency::query()->create([
            'name' => 'Lebanese Pound',
            'code' => 'LBP',
            'iso_code' => 'LBP',
            'symbol' => 'LL',
            'is_active' => true,
        ]);

        CompanySetting::singleton()->update([
            'primary_currency_id' => $usd->id,
            'tax_enabled' => false,
            'allow_negative_stock' => false,
        ]);
        app(CompanySettingService::class)->forgetCache();

        CurrencyPairRate::query()->create([
            'from_currency_id' => $usd->id,
            'to_currency_id' => $lbp->id,
            'rate' => 89500,
            'effective_from' => now(),
        ]);

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

        $vat = VatGroup::query()->create([
            'abrv' => 'VAT0',
            'name' => 'VAT 0',
            'percentage' => 0,
            'is_default' => true,
            'is_active' => true,
        ]);

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $inventoryType = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();

        $stock = Item::query()->create([
            'name' => 'Stock item',
            'item_code' => 'STK-FX',
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
            'selling_price' => 15,
            'cost_price' => 8,
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'FX Supplier',
            'is_active' => true,
        ]);

        return [
            'usd_id' => (int) $usd->id,
            'lbp_id' => (int) $lbp->id,
            'warehouse_id' => (int) $warehouse->id,
            'supplier_id' => (string) $supplier->id,
            'stock_item_id' => (string) $stock->id,
        ];
    }
}
