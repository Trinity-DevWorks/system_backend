<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Brand\Models\Brand;
use App\Modules\Category\Models\Category;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemVariant\Models\ItemAttribute;
use App\Modules\Inventory\ItemVariant\Models\ItemAttributeValue;
use App\Modules\Inventory\ItemVariant\Services\ItemVariantService;
use App\Modules\Inventory\PriceChange\Models\ItemPriceChange;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Rbac\Models\Permission;
use App\Modules\Rbac\Models\Role;
use App\Modules\Rbac\Models\RolePermission;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

class PriceChangeTest extends TestCase
{
    use InteractsWithTenant;

    private string $token;

    private int $uomId;

    private int $categoryId;

    private string $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->token = $this->tenantBearerToken();
        $this->tenant->run(function (): void {
            $currency = Currency::query()->create([
                'name' => 'US Dollar',
                'code' => 'USD',
                'iso_code' => 'USD',
                'symbol' => '$',
                'is_active' => true,
            ]);
            CompanySetting::current()->update(['primary_currency_id' => $currency->id]);
            app(CompanySettingService::class)->forgetCache();

            $group = UnitGroup::query()->create([
                'code' => 'CNT',
                'name' => 'Count',
                'dimension_type' => 'count',
                'is_active' => true,
            ]);
            $uom = UnitOfMeasurement::query()->create([
                'unit_group_id' => $group->id,
                'code' => 'PCS',
                'name' => 'Piece',
                'symbol' => 'pcs',
                'decimal_places' => 0,
                'is_active' => true,
            ]);
            $category = Category::query()->create(['code' => 'BEV', 'name' => 'Beverages', 'color' => '#2563eb', 'is_active' => true]);
            $brand = Brand::query()->create(['code' => 'PEP', 'name' => 'Pepsi', 'is_active' => true]);
            $typeId = (int) DB::table('item_types')->orderBy('id')->value('id');
            $item = Item::query()->create([
                'name' => 'Pepsi',
                'item_code' => 'PEPSI',
                'item_type_id' => $typeId,
                'category_id' => $category->id,
                'brand_id' => $brand->id,
                'unit_group_id' => $group->id,
                'track_inventory' => true,
                'allow_sale' => true,
                'allow_purchase' => true,
                'is_active' => true,
            ]);
            $this->uomId = (int) $uom->id;
            $this->categoryId = (int) $category->id;
            $this->itemId = (string) $item->id;
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_a_price_edit_logs_old_new_and_user_and_an_unchanged_save_logs_nothing(): void
    {
        $created = $this->callJson('POST', "items/{$this->itemId}/item-uoms", [
            'uom_id' => $this->uomId,
            'conversion_factor' => 1,
            'selling_price' => '1.50',
            'cost_price' => '1.00',
            'is_base' => true,
        ]);
        $this->assertSame(201, $created->status(), (string) $created->getContent());
        $uomId = (int) $created->json('data.id');

        $changed = $this->callJson('PUT', "items/{$this->itemId}/item-uoms/{$uomId}", [
            'selling_price' => '1.75',
            'reason' => 'Summer price',
        ]);
        $changed->assertOk();

        $history = $this->callJson('GET', "items/{$this->itemId}/price-history");
        $history->assertOk();
        $selling = array_values(array_filter(
            $history->json('data.rows'),
            fn (array $row): bool => $row['field'] === 'selling_price' && $row['old_value'] !== null,
        ));
        $this->assertCount(1, $selling);
        $this->assertSame('1.500000', $selling[0]['old_value']);
        $this->assertSame('1.750000', $selling[0]['new_value']);
        $this->assertSame('16.6667', $selling[0]['change_percent']);
        $this->assertSame('Summer price', $selling[0]['reason']);
        $this->assertSame('manual', $selling[0]['source']);
        $this->assertSame((string) $this->tenantUser->id, $selling[0]['changed_by']);
        $this->assertNotEmpty($history->json('data.series'));

        $before = $history->json('data.rows');
        $this->callJson('PUT', "items/{$this->itemId}/item-uoms/{$uomId}", [
            'selling_price' => '1.75',
            'reason' => 'Same price',
        ])->assertOk();
        $after = $this->callJson('GET', "items/{$this->itemId}/price-history");
        $this->assertCount(count($before), $after->json('data.rows'));
    }

    public function test_price_list_edits_and_variant_generation_are_logged(): void
    {
        $uomId = $this->addUnit('2.00', '1.00');
        $currencyId = $this->tenant->run(fn (): int => (int) CompanySetting::current()->primary_currency_id);

        $created = $this->callJson('POST', 'price-lists', [
            'code' => 'RETAIL',
            'name' => 'Retail',
            'currency_id' => $currencyId,
            'items' => [[
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'min_quantity' => 0,
                'price' => '2.50',
                'reason' => 'Opening list',
            ]],
        ]);
        $created->assertCreated();
        $listId = (int) $created->json('data.id');

        $this->callJson('PUT', "price-lists/{$listId}", [
            'items' => [[
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'min_quantity' => 0,
                'price' => '2.75',
                'reason' => 'List increase',
            ]],
        ])->assertOk();

        $this->callJson('PUT', "price-lists/{$listId}", [
            'items' => [[
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'min_quantity' => 0,
                'price' => '2.75',
            ]],
        ])->assertOk();

        $rows = $this->tenant->run(fn () => ItemPriceChange::query()
            ->where('item_id', $this->itemId)
            ->where('field', 'price_list_price')
            ->orderBy('id')
            ->get());
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]->old_value);
        $this->assertSame('2.500000', (string) $rows[0]->new_value);
        $this->assertSame('Opening list', $rows[0]->reason);
        $this->assertSame('2.500000', (string) $rows[1]->old_value);
        $this->assertSame('2.750000', (string) $rows[1]->new_value);

        $this->tenant->run(function (): void {
            $attribute = ItemAttribute::query()->create(['code' => 'SIZE', 'name' => 'Size', 'sort_order' => 1]);
            $value = ItemAttributeValue::query()->create([
                'item_attribute_id' => $attribute->id,
                'code' => 'S',
                'name' => 'Small',
                'sort_order' => 1,
            ]);
            app(ItemVariantService::class)->generate(Item::query()->findOrFail($this->itemId), [[
                'attribute_id' => $attribute->id,
                'value_ids' => [$value->id],
            ]]);
        });

        $copied = $this->tenant->run(fn () => ItemPriceChange::query()->where('source', 'variant_generate')->count());
        $this->assertGreaterThan(0, $copied);
    }

    public function test_bulk_fill_rounds_and_apply_now_versus_scheduled_is_idempotent(): void
    {
        $uomId = $this->addUnit('10.00', '4.00');

        $percent = $this->callJson('POST', 'price-changes/bulk-preview', [
            'item_ids' => [$this->itemId],
            'field' => 'selling_price',
            'rule' => 'percent',
            'value' => 5,
            'rounding' => 'none',
        ]);
        $percent->assertOk();
        $this->assertSame('10.500000', $percent->json('data.lines.0.new_value'));

        $step = $this->callJson('POST', 'price-changes/bulk-preview', [
            'item_ids' => [$this->itemId],
            'field' => 'selling_price',
            'rule' => 'amount',
            'value' => '0.12',
            'rounding' => '0.05',
        ]);
        $step->assertOk();
        $this->assertSame('10.100000', $step->json('data.lines.0.new_value'));

        $margin = $this->callJson('POST', 'price-changes/bulk-preview', [
            'item_ids' => [$this->itemId],
            'category_id' => $this->categoryId,
            'field' => 'selling_price',
            'rule' => 'margin',
            'value' => 50,
            'rounding' => 'none',
        ]);
        $margin->assertOk();
        $this->assertSame('6.000000', $margin->json('data.lines.0.new_value'));

        $this->tenant->run(function () use ($uomId): void {
            ItemUom::query()->whereKey($uomId)->update(['selling_price' => '10.400000']);
        });
        $charm = $this->callJson('POST', 'price-changes/bulk-preview', [
            'item_ids' => [$this->itemId],
            'field' => 'selling_price',
            'rule' => 'percent',
            'value' => 0,
            'rounding' => '0.99',
        ]);
        $charm->assertOk();
        $this->assertSame('9.990000', $charm->json('data.lines.0.new_value'));

        $now = $this->callJson('POST', 'price-changes', [
            'effective_date' => '2000-01-01',
            'notes' => 'Apply now',
            'lines' => [[
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'field' => 'selling_price',
                'new_value' => '11.00',
            ]],
        ]);
        $now->assertCreated();
        $applied = $this->callJson('POST', 'price-changes/'.$now->json('data.id').'/apply');
        $applied->assertOk();
        $applied->assertJsonPath('data.status', 'applied');
        $applied->assertJsonPath('data.changed_item_ids.0', $this->itemId);
        $this->assertSame(
            '11.000000',
            $this->tenant->run(fn (): string => (string) ItemUom::query()->whereKey($uomId)->value('selling_price')),
        );
        $logged = $this->tenant->run(fn () => ItemPriceChange::query()
            ->where('source', 'price_change_doc')
            ->where('source_id', $now->json('data.id'))
            ->where('field', 'selling_price')
            ->first());
        $this->assertNotNull($logged);
        $this->assertSame('11.000000', (string) $logged->new_value);

        $later = $this->callJson('POST', 'price-changes', [
            'effective_date' => '2099-01-01',
            'notes' => 'Next year',
            'lines' => [[
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'field' => 'selling_price',
                'new_value' => '12.00',
            ]],
        ]);
        $later->assertCreated();
        $scheduled = $this->callJson('POST', 'price-changes/'.$later->json('data.id').'/apply');
        $scheduled->assertOk();
        $scheduled->assertJsonPath('data.status', 'scheduled');
        $this->assertSame(
            '11.000000',
            $this->tenant->run(fn (): string => (string) ItemUom::query()->whereKey($uomId)->value('selling_price')),
        );

        $this->artisan('prices:apply-scheduled', [
            '--date' => '2099-01-01',
            '--tenant' => [$this->tenant->id],
        ])->assertSuccessful();
        $this->assertSame(
            '12.000000',
            $this->tenant->run(fn (): string => (string) ItemUom::query()->whereKey($uomId)->value('selling_price')),
        );
        $count = $this->tenant->run(fn (): int => ItemPriceChange::query()->where('source_id', $later->json('data.id'))->count());

        $this->artisan('prices:apply-scheduled', [
            '--date' => '2099-01-01',
            '--tenant' => [$this->tenant->id],
        ])->assertSuccessful();
        $this->assertSame(
            $count,
            $this->tenant->run(fn (): int => ItemPriceChange::query()->where('source_id', $later->json('data.id'))->count()),
        );
        $this->assertSame(
            'applied',
            $this->tenant->run(fn (): string => (string) DB::table('price_changes')->where('id', $later->json('data.id'))->value('status')),
        );
    }

    public function test_below_cost_is_refused_without_the_override_and_allowed_with_it(): void
    {
        $uomId = $this->addUnit('10.00', '8.00');
        $doc = $this->callJson('POST', 'price-changes', [
            'effective_date' => '2000-01-01',
            'lines' => [[
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'field' => 'selling_price',
                'new_value' => '5.00',
            ]],
        ]);
        $doc->assertCreated();
        $id = (string) $doc->json('data.id');

        $cashier = $this->cashierToken();
        $this->assertNotEmpty($doc->json('data.below_cost'), (string) $doc->getContent());
        $this->app['auth']->forgetGuards();
        $refused = $this->asTenantRequest($cashier)->json('POST', $this->tenantUrl("price-changes/{$id}/apply"));
        $this->assertSame(422, $refused->status(), (string) $refused->getContent());
        $refused->assertJsonPath('code', 'PRICE_BELOW_COST');
        $this->assertSame('PEPSI', $refused->json('data.below_cost.0.item_code'));
        $this->assertSame(
            '10.000000',
            $this->tenant->run(fn (): string => (string) ItemUom::query()->whereKey($uomId)->value('selling_price')),
        );

        $allowed = $this->callJson('POST', "price-changes/{$id}/apply");
        $allowed->assertOk();
        $allowed->assertJsonPath('data.status', 'applied');
        $this->assertNotEmpty($allowed->json('data.below_cost'));
        $this->assertSame(
            '5.000000',
            $this->tenant->run(fn (): string => (string) ItemUom::query()->whereKey($uomId)->value('selling_price')),
        );
    }

    public function test_reports_and_rbac(): void
    {
        $uomId = $this->addUnit('5.00', '1.00');
        $this->tenant->run(function () use ($uomId): void {
            $warehouse = Warehouse::query()->create([
                'name' => 'Central',
                'shortcut_name' => 'CTR',
                'type' => 'central',
                'manager_id' => $this->tenantUser->id,
                'is_active' => true,
            ]);
            DB::table('stock_balances')->insert([
                'item_id' => $this->itemId,
                'warehouse_id' => $warehouse->id,
                'quantity' => '2',
                'unit_cost' => '8',
                'inventory_value' => '16',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $supplier = Supplier::query()->create(['name' => 'Bottler', 'is_active' => true]);
            $branchId = (int) Branch::query()->orderBy('id')->value('id');
            $invoice = PurchaseInvoice::query()->create([
                'branch_id' => $branchId,
                'invoice_number' => 'PI-1',
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'currency_id' => (int) CompanySetting::current()->primary_currency_id,
                'status' => 'posted',
                'invoice_date' => '2026-11-01',
                'due_on' => '2026-11-15',
                'exchange_rate' => '2',
            ]);
            PurchaseInvoiceLine::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'item_id' => $this->itemId,
                'item_uom_id' => $uomId,
                'quantity' => '1',
                'base_quantity' => '1',
                'unit_price' => '12',
            ]);
        });

        $below = $this->callJson('GET', 'reports/below-cost');
        $below->assertOk();
        $this->assertSame('PEPSI Pepsi', $below->json('data.rows.0.item'));
        $this->assertSame('-3.000000', $below->json('data.totals.gap'));

        $period = $this->callJson('GET', 'reports/price-changes?from=2000-01-01&to=2099-12-31');
        $period->assertOk();
        $this->assertSame(count($period->json('data.rows')), $period->json('data.totals.changes'));

        $history = $this->callJson('GET', 'reports/price-history?item_id='.$this->itemId);
        $history->assertOk();
        $this->assertNotEmpty($history->json('data.rows'));

        $purchases = $this->callJson('GET', 'reports/purchase-price-history?item_id='.$this->itemId);
        $purchases->assertOk();
        $this->assertSame('6.000000', $purchases->json('data.rows.0.unit_price'));
        $this->assertSame(1, $purchases->json('data.totals.changes'));

        $cashier = $this->cashierToken(false);
        $this->app['auth']->forgetGuards();
        $this->asTenantRequest($cashier)->json('GET', $this->tenantUrl('price-changes'))
            ->assertForbidden();
    }

    private function addUnit(string $selling, string $cost): int
    {
        $created = $this->callJson('POST', "items/{$this->itemId}/item-uoms", [
            'uom_id' => $this->uomId,
            'conversion_factor' => 1,
            'selling_price' => $selling,
            'cost_price' => $cost,
            'is_base' => true,
        ]);
        $this->assertSame(201, $created->status(), (string) $created->getContent());

        return (int) $created->json('data.id');
    }

    private function cashierToken(bool $canEditPrices = true): string
    {
        return $this->tenant->run(function () use ($canEditPrices): string {
            $role = Role::query()->create([
                'name' => $canEditPrices ? 'Pricer' : 'Viewer',
                'is_active' => true,
            ]);
            $branchId = (int) Branch::query()->orderBy('id')->value('id');
            $user = User::factory()->create([
                'email' => ($canEditPrices ? 'pricer' : 'viewer').'@test.local',
                'is_active' => true,
            ]);
            $user->branches()->attach($branchId, ['role_id' => $role->id]);
            if ($canEditPrices) {
                $permission = Permission::query()->where('resource_key', 'price_changes')->firstOrFail();
                RolePermission::query()->create([
                    'role_id' => $role->id,
                    'permission_id' => $permission->id,
                    'can_view' => true,
                    'can_add' => true,
                    'can_edit' => true,
                    'can_delete' => false,
                    'can_import' => false,
                    'can_export' => false,
                    'can_reverse' => false,
                    'can_reopen' => false,
                    'can_print' => false,
                ]);
            }

            return $user->createToken('tests')->plainTextToken;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function callJson(string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->asTenantRequest($this->token)->json($method, $this->tenantUrl($path), $data);
    }
}
