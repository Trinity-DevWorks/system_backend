<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Jobs\BootstrapTenantItemTypes;
use App\Jobs\BootstrapTenantUnitCatalog;
use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Price lists CRUD and default line price resolution
 * (customer -> customer group -> branch -> default list -> item selling price).
 */
#[Group('tenant-db')]
class PriceListApiTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    /** @var array<string, mixed> */
    private array $catalog = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('price_list_v1');
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

    public function test_falls_back_to_item_selling_price_converted_with_the_rate(): void
    {
        $this->assertSame(['10.00', 'catalog'], $this->resolve($this->catalog['usd_id'], '1', 1));
        $this->assertSame(['5.00', 'catalog'], $this->resolve($this->catalog['eur_id'], '0.5', 1));
    }

    public function test_resolution_order_customer_then_group_then_branch_then_default(): void
    {
        $default = $this->createList('DEF', '9', ['is_default' => true]);
        $this->assertSame(['9.00', 'DEF'], $this->resolve($this->catalog['usd_id'], '1', 1));

        $branchList = $this->createList('BR', '8');
        $this->tenant->run(fn () => Branch::query()->whereKey($this->catalog['branch_id'])->update(['price_list_id' => $branchList]));
        $this->assertSame(['8.00', 'BR'], $this->resolve($this->catalog['usd_id'], '1', 1));

        $groupList = $this->createList('GRP', '7');
        $this->tenant->run(fn () => CustomerGroup::query()->whereKey($this->catalog['group_id'])->update(['price_list_id' => $groupList]));
        $this->assertSame(['7.00', 'GRP'], $this->resolve($this->catalog['usd_id'], '1', 1));

        $customerList = $this->createList('CUS', '6');
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl('/customers/'.$this->catalog['customer_id']), ['price_list_id' => $customerList])
            ->assertOk()
            ->assertJsonPath('data.price_list_id', $customerList);
        $this->assertSame(['6.00', 'CUS'], $this->resolve($this->catalog['usd_id'], '1', 1));

        $this->assertNotSame(0, $default);
    }

    public function test_quantity_breaks_validity_and_currency(): void
    {
        $id = $this->createList('BRK', '6', ['is_default' => true], [['min_quantity' => 10, 'price' => '5.5']]);
        $this->assertSame(['6.00', 'BRK'], $this->resolve($this->catalog['usd_id'], '1', 9));
        $this->assertSame(['5.50', 'BRK'], $this->resolve($this->catalog['usd_id'], '1', 12));

        // A primary-currency list is converted for a EUR document (1 USD = 0.5 EUR).
        $this->assertSame(['3.00', 'BRK'], $this->resolve($this->catalog['eur_id'], '0.5', 1));

        // An expired list is skipped.
        $this->asTenantRequest($this->token)
            ->putJson($this->tenantUrl("/price-lists/{$id}"), ['valid_from' => '2020-01-01', 'valid_to' => '2020-12-31'])
            ->assertOk();
        $this->assertSame(['10.00', 'catalog'], $this->resolve($this->catalog['usd_id'], '1', 1));

        // A EUR list is used as-is for EUR documents and ignored for USD ones.
        $this->createList('EURL', '4', ['currency_id' => $this->catalog['eur_id'], 'is_default' => true]);
        $this->assertSame(['4.00', 'EURL'], $this->resolve($this->catalog['eur_id'], '0.5', 1));
        $this->assertSame(['10.00', 'catalog'], $this->resolve($this->catalog['usd_id'], '1', 1));
    }

    public function test_validation_rejects_duplicates_and_foreign_units(): void
    {
        $payload = $this->listPayload('DUP', '5');
        $payload['items'][] = $payload['items'][0];

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/price-lists'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRICE_LIST_DUPLICATE_ITEM');

        $payload = $this->listPayload('MIS', '5');
        $payload['items'][0]['item_uom_id'] = $this->catalog['other_uom_id'];

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/price-lists'), $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRICE_LIST_ITEM_UOM_MISMATCH');
    }

    public function test_crud_and_single_default(): void
    {
        $a = $this->createList('AAA', '5', ['is_default' => true]);
        $b = $this->createList('BBB', '6', ['is_default' => true]);

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/price-lists/{$a}"))
            ->assertOk()
            ->assertJsonPath('data.is_default', false)
            ->assertJsonPath('data.items.0.price', '5.000000');

        $this->asTenantRequest($this->token)->getJson($this->tenantUrl('/price-lists'))
            ->assertOk();

        $this->asTenantRequest($this->token)->deleteJson($this->tenantUrl("/price-lists/{$b}"))->assertOk();
        $this->asTenantRequest($this->token)->getJson($this->tenantUrl("/price-lists/{$b}"))->assertNotFound();
    }

    /**
     * @return array{0: ?string, 1: string}
     */
    private function resolve(int $currencyId, string $rate, int|float $qty): array
    {
        $row = $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/pricing/resolve'), [
                'customer_id' => $this->catalog['customer_id'],
                'currency_id' => $currencyId,
                'exchange_rate' => $rate,
                'date' => '2026-06-01',
                'lines' => [['item_id' => $this->catalog['item_id'], 'item_uom_id' => $this->catalog['uom_id'], 'quantity' => $qty]],
            ])
            ->assertOk()
            ->json('data.0');

        return [$row['unit_price'], $row['price_source']];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  list<array{min_quantity: int|float, price: string}>  $extraBreaks
     */
    private function createList(string $code, string $price, array $overrides = [], array $extraBreaks = []): int
    {
        $payload = array_merge($this->listPayload($code, $price), $overrides);
        foreach ($extraBreaks as $break) {
            $payload['items'][] = ['item_id' => $this->catalog['item_id'], 'item_uom_id' => $this->catalog['uom_id'], ...$break];
        }

        return (int) $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl('/price-lists'), $payload)
            ->assertCreated()
            ->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function listPayload(string $code, string $price): array
    {
        return [
            'code' => $code,
            'name' => 'List '.$code,
            'currency_id' => $this->catalog['usd_id'],
            'items' => [[
                'item_id' => $this->catalog['item_id'],
                'item_uom_id' => $this->catalog['uom_id'],
                'min_quantity' => 0,
                'price' => $price,
            ]],
        ];
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
            'tax_enabled' => false,
            'price_decimal_places' => 2,
        ]);
        app(CompanySettingService::class)->forgetCache();

        $unitGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
        $uom = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
        $type = ItemType::query()->where('code', 'SERVICE')->firstOrFail();

        $makeItem = function (string $code) use ($type, $unitGroup, $uom): array {
            $item = Item::query()->create([
                'name' => 'Item '.$code,
                'item_code' => $code,
                'item_type_id' => $type->id,
                'unit_group_id' => $unitGroup->id,
                'base_uom_id' => $uom->id,
                'track_inventory' => false,
                'track_lots' => false,
                'allow_sale' => true,
                'allow_purchase' => true,
                'is_active' => true,
            ]);
            $itemUom = ItemUom::query()->create([
                'item_id' => $item->id,
                'uom_id' => $uom->id,
                'conversion_factor' => 1,
                'selling_price' => 10,
                'is_base' => true,
                'is_default_sale' => true,
            ]);

            return [(string) $item->id, (int) $itemUom->id];
        };

        [$itemId, $uomId] = $makeItem('PL-1');
        [, $otherUomId] = $makeItem('PL-2');

        $group = CustomerGroup::query()->create(['code' => 'VIP', 'name' => 'VIP', 'is_active' => true]);
        $customer = Customer::query()->create([
            'name' => 'Price Customer',
            'type' => 'individual',
            'status' => 'active',
            'customer_group_id' => $group->id,
        ]);
        $branch = Branch::query()->where('is_default', true)->firstOrFail();

        return [
            'usd_id' => (int) $usd->id,
            'eur_id' => (int) $eur->id,
            'item_id' => $itemId,
            'uom_id' => $uomId,
            'other_uom_id' => $otherUomId,
            'customer_id' => (string) $customer->id,
            'group_id' => (int) $group->id,
            'branch_id' => (int) $branch->id,
        ];
    }
}
