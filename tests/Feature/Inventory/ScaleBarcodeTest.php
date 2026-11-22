<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleBarcodeFormatService;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleBarcodeService;
use App\Modules\Inventory\ScaleBarcode\Support\CheckDigit;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Support\DocumentTaxContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Scale labels through the barcode lookup: exact barcodes first, then the formats.
 */
class ScaleBarcodeTest extends TestCase
{
    use InteractsWithTenant;

    private string $token;

    private int $kgId;

    private string $itemId;

    private int $itemUomId;

    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->token = $this->tenantBearerToken();
        $this->tenant->run(function (): void {
            $currency = Currency::query()->create([
                'name' => 'US Dollar', 'code' => 'USD', 'iso_code' => 'USD', 'symbol' => '$', 'is_active' => true,
            ]);
            CompanySetting::current()->update(['primary_currency_id' => $currency->id]);
            app(CompanySettingService::class)->forgetCache();

            $group = UnitGroup::query()->create(['code' => 'WGT', 'name' => 'Weight', 'dimension_type' => 'weight', 'is_active' => true]);
            $kg = UnitOfMeasurement::query()->create([
                'unit_group_id' => $group->id, 'code' => 'KG', 'name' => 'Kilogram', 'symbol' => 'kg', 'decimal_places' => 3, 'is_active' => true,
            ]);
            $item = Item::query()->create([
                'name' => 'Cheese',
                'item_code' => 'CHEESE',
                'plu_code' => '01234',
                'is_scale_item' => true,
                'item_type_id' => (int) DB::table('item_types')->orderBy('id')->value('id'),
                'unit_group_id' => $group->id,
                'base_uom_id' => $kg->id,
                'track_inventory' => true,
                'allow_sale' => true,
                'is_active' => true,
            ]);
            $itemUom = ItemUom::query()->create([
                'item_id' => $item->id, 'uom_id' => $kg->id, 'conversion_factor' => 1,
                'selling_price' => '10.00', 'is_base' => true, 'is_default_sale' => true,
            ]);
            $this->kgId = (int) $kg->id;
            $this->itemId = (string) $item->id;
            $this->itemUomId = (int) $itemUom->id;
            $this->branchId = (int) Branch::query()->orderBy('id')->value('id');
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_weight_label_gives_the_weight_as_quantity(): void
    {
        $this->createWeightFormat();

        $response = $this->lookup(self::ean('210123401250'));
        $response->assertOk();
        $this->assertSame('scale', $response->json('data.source'));
        $this->assertSame($this->itemId, $response->json('data.item.id'));
        $this->assertSame($this->itemUomId, (int) $response->json('data.item_uom.id'));
        $this->assertSame('1.250000', $response->json('data.scale.quantity'));
        $this->assertSame('catalog', $response->json('data.scale.price_source'));
    }

    public function test_an_exact_barcode_wins_over_a_scale_format(): void
    {
        $this->createWeightFormat();
        $code = self::ean('210123401250');
        $this->tenant->run(fn () => ItemUom::query()->whereKey($this->itemUomId)->update(['barcode' => $code]));

        $response = $this->lookup($code);
        $response->assertOk();
        $this->assertSame('item_uoms', $response->json('data.source'));
        $this->assertNull($response->json('data.scale'));
    }

    public function test_price_label_works_out_the_weight_from_the_unit_price(): void
    {
        $this->tenant->run(function (): void {
            app(ScaleBarcodeFormatService::class)->create([
                'name' => 'Price label',
                'engine' => 'fixed',
                'symbology' => 'ean13',
                'prefixes' => ['22'],
                'plu_start' => 3, 'plu_length' => 5,
                'value_start' => 8, 'value_length' => 5,
                'value_type' => 'price',
                'decimals' => 2,
                'price_includes_tax' => DocumentTaxContext::pricesIncludeTax(),
            ]);
        });

        // 22 | PLU 01234 | 01250 → 12.50 at 10.00 / kg → 1.25 kg
        $response = $this->lookup(self::ean('220123401250'));
        $response->assertOk();
        $this->assertSame('1.250000', $response->json('data.scale.quantity'));
        $this->assertSame('12.500000', $response->json('data.scale.embedded_price'));
        $this->assertSame('scale', $response->json('data.scale.price_source'));
    }

    public function test_unknown_plu_is_an_error_not_a_guess(): void
    {
        $this->createWeightFormat();

        $response = $this->lookup(self::ean('219999901250'));
        $response->assertStatus(404);
        $this->assertSame('SCALE_BARCODE_UNKNOWN_PLU', $response->json('code'));
        $this->assertSame('99999', $response->json('data.params.plu'));

        // Not a scale label at all: the usual "not found".
        $this->lookup('4006381333931')->assertStatus(404)->assertJsonPath('code', 'BARCODE_NOT_FOUND');
    }

    public function test_reserved_prefix_and_overlapping_formats_are_refused(): void
    {
        $this->createWeightFormat();
        $this->tenant->run(function (): void {
            $service = app(ScaleBarcodeFormatService::class);
            $this->assertErrorCode('SCALE_BARCODE_PREFIX_RESERVED', fn () => $service->create($this->weightData(['prefixes' => ['20']])));
            $this->assertErrorCode('SCALE_BARCODE_FORMAT_OVERLAP', fn () => $service->create($this->weightData(['name' => 'Copy'])));
            $this->assertErrorCode('SCALE_BARCODE_FORMAT_POSITIONS_INVALID', fn () => $service->create($this->weightData([
                'prefixes' => ['24'], 'value_start' => 6,
            ])));
        });
    }

    public function test_a_format_limited_to_a_branch_is_not_used_elsewhere(): void
    {
        $this->tenant->run(function (): void {
            app(ScaleBarcodeFormatService::class)->create($this->weightData(['branch_ids' => [$this->branchId]]));
            $service = app(ScaleBarcodeService::class);
            $code = self::ean('210123401250');

            $this->assertNotNull($service->resolve($code, $this->branchId));
            $this->assertNull((new ScaleBarcodeService(app(ExchangeRateService::class)))->resolve($code, $this->branchId + 1000));
        });
    }

    public function test_gs1_without_plu_positions_finds_the_plu_padded_inside_the_gtin(): void
    {
        $this->createGs1WeightFormat();
        // GTIN = zeros + PLU 01234 + check digit.
        $gtin = self::ean('0000000001234');

        $response = $this->lookup('(01)'.$gtin.'(3103)001250');
        $response->assertOk();
        $this->assertSame($this->itemId, $response->json('data.item.id'));
        $this->assertSame('1.250000', $response->json('data.scale.quantity'));
    }

    public function test_gs1_gtin_matching_an_item_barcode_wins_over_the_plu(): void
    {
        $this->createGs1WeightFormat();
        $gtin = self::ean('0000000001234');
        $otherId = $this->tenant->run(function () use ($gtin): string {
            $base = Item::query()->findOrFail($this->itemId);
            $other = Item::query()->create([
                'name' => 'Packed cheese',
                'item_code' => 'PACKED',
                'item_type_id' => $base->item_type_id,
                'unit_group_id' => $base->unit_group_id,
                'base_uom_id' => $this->kgId,
                'track_inventory' => true,
                'allow_sale' => true,
                'is_active' => true,
            ]);
            ItemUom::query()->create([
                'item_id' => $other->id, 'uom_id' => $this->kgId, 'conversion_factor' => 1,
                'selling_price' => '12.00', 'is_base' => true, 'is_default_sale' => true,
                'barcode' => substr($gtin, 1), // stored as EAN-13
            ]);

            return (string) $other->id;
        });

        $response = $this->lookup('(01)'.$gtin.'(3103)001250');
        $response->assertOk();
        $this->assertSame($otherId, $response->json('data.item.id'));
    }

    public function test_gs1_pound_label_is_converted_to_the_items_kg_unit(): void
    {
        $this->createGs1WeightFormat();
        $gtin = self::ean('0000000001234');

        // 3202 = 4.50 lb → 2.041 kg (item only has KG; rounded to the KG unit's 3 decimals).
        $response = $this->lookup('(01)'.$gtin.'(3202)000450');
        $response->assertOk();
        $this->assertSame($this->itemUomId, (int) $response->json('data.item_uom.id'));
        $this->assertSame('2.041000', $response->json('data.scale.quantity'));
        $this->assertSame('lb', $response->json('data.scale.weight_unit'));
    }

    public function test_gs1_label_with_kg_and_lb_weights_must_agree(): void
    {
        $this->createGs1WeightFormat();
        $gtin = self::ean('0000000001234');

        // 4.50 lb and 1.25 kg: contradiction → refused.
        $bad = $this->lookup('(01)'.$gtin.'(3202)000450(3103)001250');
        $bad->assertStatus(422);
        $this->assertSame('SCALE_BARCODE_WEIGHTS_DISAGREE', $bad->json('code'));

        // 4.50 lb and 2.041 kg agree: the kg weight is used as printed (item has KG).
        $good = $this->lookup('(01)'.$gtin.'(3202)000450(3103)002041');
        $good->assertOk();
        $this->assertSame('2.041000', $good->json('data.scale.quantity'));
    }

    public function test_gs1_weight_and_price_on_one_label(): void
    {
        $this->createGs1WeightFormat(['price_includes_tax' => 'company']);
        $gtin = self::ean('0000000001234');

        // 1.250 kg for 14.99 → 11.992 per kg (item price 10.00 → warning).
        $response = $this->lookup('(01)'.$gtin.'(3103)001250(3922)1499');
        $response->assertOk();
        $this->assertSame('1.250000', $response->json('data.scale.quantity'));
        $this->assertSame('11.992000', $response->json('data.scale.unit_price_primary'));
        $this->assertSame('scale', $response->json('data.scale.price_source'));
        $this->assertSame('SCALE_LABEL_PRICE_DIFFERS', $response->json('data.scale.warnings.0.code'));
    }

    public function test_expired_labels_are_refused_and_lots_are_matched(): void
    {
        $this->createGs1WeightFormat();
        $gtin = self::ean('0000000001234');

        $expired = $this->lookup('(01)'.$gtin.'(3103)001250(17)200101');
        $expired->assertStatus(422);
        $this->assertSame('SCALE_BARCODE_EXPIRED', $expired->json('code'));

        // Lot is ignored for items that don't track lots…
        $this->lookup('(01)'.$gtin.'(3103)001250(10)B7')->assertOk()->assertJsonPath('data.scale.lot_id', null);

        // …and must exist for items that do.
        $lotId = $this->tenant->run(function (): int {
            Item::query()->whereKey($this->itemId)->update(['track_lots' => true]);

            return (int) InventoryLot::query()->create(['item_id' => $this->itemId, 'lot_number' => 'B7'])->id;
        });
        $this->lookup('(01)'.$gtin.'(3103)001250(10)b7')->assertOk()->assertJsonPath('data.scale.lot_id', $lotId);
        $unknown = $this->lookup('(01)'.$gtin.'(3103)001250(10)ZZ9');
        $unknown->assertStatus(422);
        $this->assertSame('SCALE_BARCODE_UNKNOWN_LOT', $unknown->json('code'));
    }

    public function test_the_settings_test_box_reads_an_unsaved_format(): void
    {
        $this->tenant->run(function (): void {
            $result = app(ScaleBarcodeService::class)->test($this->weightData(), self::ean('210123401250'));
            $this->assertTrue($result['matched']);
            $this->assertSame('1.250000', $result['line']['scale']['quantity']);

            $miss = app(ScaleBarcodeService::class)->test($this->weightData(), self::ean('219999901250'));
            $this->assertSame('SCALE_BARCODE_UNKNOWN_PLU', $miss['error']['code']);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGs1WeightFormat(array $overrides = []): void
    {
        $this->tenant->run(function () use ($overrides): void {
            if (($overrides['price_includes_tax'] ?? null) === 'company') {
                $overrides['price_includes_tax'] = DocumentTaxContext::pricesIncludeTax();
            }
            app(ScaleBarcodeFormatService::class)->create([
                'name' => 'GS1 weight',
                'engine' => 'gs1',
                'symbology' => 'gs1_128',
                'value_type' => 'weight',
                ...$overrides,
            ]);
        });
    }

    private function createWeightFormat(): void
    {
        $this->tenant->run(fn () => app(ScaleBarcodeFormatService::class)->create($this->weightData()));
    }

    /**
     * 21 | PLU 5 | grams 5 | check — read in kg.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function weightData(array $overrides = []): array
    {
        return [
            'name' => 'Weight label',
            'engine' => 'fixed',
            'symbology' => 'ean13',
            'prefixes' => ['21'],
            'plu_start' => 3, 'plu_length' => 5,
            'value_start' => 8, 'value_length' => 5,
            'value_type' => 'weight',
            'decimals' => 3,
            'uom_id' => $this->kgId,
            ...$overrides,
        ];
    }

    private function lookup(string $barcode): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->asTenantRequest($this->token)->getJson($this->tenantUrl('items/lookup-by-barcode?barcode='.urlencode($barcode)));
    }

    private static function ean(string $body): string
    {
        return $body.CheckDigit::mod10($body);
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
