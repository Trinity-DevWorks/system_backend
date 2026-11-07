<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Warehouse\Enums\WarehouseType;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Item variants: attributes + values, a template item and generated child items.
 */
#[Group('tenant-db')]
class ItemVariantApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant('item_variants_v1');
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

    public function test_generate_creates_combinations_and_regenerate_only_adds_missing(): void
    {
        [$size, $color] = [$this->attribute('SIZE', ['S', 'M']), $this->attribute('COLOR', ['RED', 'BLUE'])];

        $result = $this->generate([
            ['attribute_id' => $size['id'], 'value_ids' => array_column($size['values'], 'id')],
            ['attribute_id' => $color['id'], 'value_ids' => array_column($color['values'], 'id')],
        ])->assertOk()->json('data');

        $this->assertSame(4, $result['created']);
        $codes = array_column($result['variants'], 'item_code');
        sort($codes);
        $this->assertSame(['TSHIRT-M-BLUE', 'TSHIRT-M-RED', 'TSHIRT-S-BLUE', 'TSHIRT-S-RED'], $codes);
        $this->assertSame('15.000000', (string) $result['variants'][0]['selling_price']);

        // Add size L: only the two new combinations are created.
        $size = $this->updateAttribute($size, ['S', 'M', 'L']);
        $again = $this->generate([
            ['attribute_id' => $size['id'], 'value_ids' => array_column($size['values'], 'id')],
            ['attribute_id' => $color['id'], 'value_ids' => array_column($color['values'], 'id')],
        ])->assertOk()->json('data');
        $this->assertSame(2, $again['created']);
        $this->assertCount(6, $again['variants']);

        // Attributes are fixed once children exist.
        $this->generate([['attribute_id' => $size['id'], 'value_ids' => [$size['values'][0]['id']]]])
            ->assertUnprocessable()->assertJsonPath('code', 'ITEM_VARIANT_ATTRIBUTES_LOCKED');

        // A used value cannot be removed; a used attribute cannot be deleted.
        $this->asTenantRequest($this->token)->putJson($this->tenantUrl("/item-attributes/{$size['id']}"), [
            'code' => 'SIZE', 'name' => 'Size', 'values' => [['id' => $size['values'][0]['id'], 'code' => 'S', 'name' => 'S']],
        ])->assertStatus(409)->assertJsonPath('code', 'ITEM_ATTRIBUTE_VALUE_IN_USE');
        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl("/item-attributes/{$size['id']}"))
            ->assertStatus(409)->assertJsonPath('code', 'ITEM_ATTRIBUTE_IN_USE');
    }

    public function test_attribute_list_is_paginated_searchable_and_has_names_and_show(): void
    {
        $size = $this->attribute('SIZE', ['S', 'M']);
        $this->attribute('COLOR', ['RED', 'BLUE']);

        $page = $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/item-attributes?per_page=1'))->assertOk()->json('data');
        $this->assertSame(2, $page['total']);
        $this->assertCount(1, $page['data']);

        // Search matches value names too.
        $found = $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/item-attributes?search=blue'))->assertOk()->json('data.data');
        $this->assertSame(['COLOR'], array_column($found, 'code'));

        $names = $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/item-attributes?section=names'))->assertOk()->json('data');
        $this->assertCount(2, $names);
        $this->assertCount(2, $names[0]['values']);

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/item-attributes/{$size['id']}"))
            ->assertOk()->assertJsonPath('data.code', 'SIZE')->assertJsonCount(2, 'data.values');
    }

    public function test_children_stock_normally_parent_is_excluded_and_delete_rules(): void
    {
        $size = $this->attribute('SIZE', ['S', 'M']);
        $variants = $this->generate([['attribute_id' => $size['id'], 'value_ids' => array_column($size['values'], 'id')]])
            ->assertOk()->json('data.variants');
        $parentId = $this->catalog['parent_id'];
        $childId = $variants[0]['id'];

        // Children move stock; the template cannot.
        $this->tenant->run(function () use ($childId, $parentId): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $childId,
                warehouseId: (int) $this->catalog['warehouse_id'],
                quantityDelta: '3',
                unitCost: '4',
                itemUomId: null,
                notes: 'opening',
                userId: null,
            ));

            try {
                app(StockMovementService::class)->post(StockMovementData::forOpening(
                    itemId: $parentId,
                    warehouseId: (int) $this->catalog['warehouse_id'],
                    quantityDelta: '1',
                    unitCost: '4',
                    itemUomId: null,
                    notes: 'opening',
                    userId: null,
                ));
                $this->fail('Parent stock should be blocked.');
            } catch (HttpException $e) {
                $this->assertSame('ITEM_VARIANT_PARENT_NOT_STOCKABLE', $e->getHeaders()['X-Error-Code'] ?? null);
            }
        });

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/items/{$parentId}/variants"))
            ->assertOk()
            ->assertJsonPath('data.variants.0.on_hand', '3.000000');

        $ids = collect($this->asTenantRequest($this->token)->getJson($this->tenantUrl('/items?section=for-invoice&context=sale&per_page=50'))
            ->assertOk()->json('data.data'))->pluck('id');
        $this->assertFalse($ids->contains($parentId));
        $this->assertTrue($ids->contains($childId));

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/items/{$parentId}"))
            ->assertOk()->assertJsonPath('data.is_variant_parent', true);
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/items/{$variants[1]['id']}"))
            ->assertOk()->assertJsonPath('data.parent_item_id', $parentId);

        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl("/items/{$parentId}"))
            ->assertStatus(409)->assertJsonPath('code', 'ITEM_DELETE_HAS_VARIANTS');
        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl("/items/{$variants[1]['id']}"))->assertOk();
    }

    public function test_item_with_stock_history_cannot_become_a_template(): void
    {
        $size = $this->attribute('SIZE', ['S']);
        $this->tenant->run(function (): void {
            app(StockMovementService::class)->post(StockMovementData::forOpening(
                itemId: $this->catalog['parent_id'],
                warehouseId: (int) $this->catalog['warehouse_id'],
                quantityDelta: '1',
                unitCost: '4',
                itemUomId: null,
                notes: 'opening',
                userId: null,
            ));
        });

        $this->generate([['attribute_id' => $size['id'], 'value_ids' => array_column($size['values'], 'id')]])
            ->assertUnprocessable()->assertJsonPath('code', 'ITEM_VARIANT_PARENT_HAS_STOCK');
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    private function attribute(string $code, array $codes): array
    {
        return $this->asTenantRequest($this->token)->postJson($this->tenantUrl('/item-attributes'), [
            'code' => $code, 'name' => ucfirst(strtolower($code)),
            'values' => array_map(fn (string $c): array => ['code' => $c, 'name' => $c], $codes),
        ])->assertCreated()->json('data');
    }

    /**
     * @param  array<string, mixed>  $attribute
     * @param  list<string>  $codes
     * @return array<string, mixed>
     */
    private function updateAttribute(array $attribute, array $codes): array
    {
        $byCode = collect($attribute['values'])->keyBy('code');

        return $this->asTenantRequest($this->token)->putJson($this->tenantUrl("/item-attributes/{$attribute['id']}"), [
            'code' => $attribute['code'], 'name' => $attribute['name'],
            'values' => array_map(fn (string $c): array => ['id' => $byCode[$c]['id'] ?? null, 'code' => $c, 'name' => $c], $codes),
        ])->assertOk()->json('data');
    }

    /**
     * @param  list<array<string, mixed>>  $attributes
     */
    private function generate(array $attributes): TestResponse
    {
        return $this->asTenantRequest($this->token)->postJson(
            $this->tenantUrl("/items/{$this->catalog['parent_id']}/variants/generate"),
            ['attributes' => $attributes],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function seedCatalog(): array
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Main WH', 'shortcut_name' => 'MWH', 'type' => WarehouseType::Central, 'manager_id' => $this->tenantUser->id,
            'is_active' => true, 'is_default' => true, 'is_default_sales' => true, 'is_default_production' => false,
            'is_default_purchase' => true, 'is_default_storage' => false,
        ]);

        $item = Item::query()->create([
            'name' => 'T-Shirt', 'item_code' => 'TSHIRT',
            'item_type_id' => ItemType::query()->where('code', 'INVENTORY')->value('id'),
            'unit_group_id' => UnitGroup::query()->where('code', 'COUNT')->value('id'),
            'base_uom_id' => UnitOfMeasurement::query()->where('code', 'EA')->value('id'),
            'track_inventory' => true, 'track_lots' => false, 'allow_sale' => true, 'allow_purchase' => true, 'is_active' => true,
        ]);
        ItemUom::query()->create([
            'item_id' => $item->id, 'uom_id' => UnitOfMeasurement::query()->where('code', 'EA')->value('id'),
            'conversion_factor' => 1, 'selling_price' => 15, 'cost_price' => 4, 'is_base' => true, 'is_default_sale' => true,
        ]);

        return ['warehouse_id' => (int) $warehouse->id, 'parent_id' => (string) $item->id];
    }
}
