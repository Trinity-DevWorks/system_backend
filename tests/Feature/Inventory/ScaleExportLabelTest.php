<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleExport;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleLabelTemplate;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleBarcodeFormatService;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleLabelService;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\Rbac\Models\Role;
use App\Modules\Sales\PriceList\Models\PriceList;
use App\Modules\Sales\PriceList\Models\PriceListItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Phase 2: PLU export file, out-of-date warning, export history, label PDFs, PLU helper.
 */
class ScaleExportLabelTest extends TestCase
{
    use InteractsWithTenant;

    private string $token;

    private int $kgId;

    private string $itemId;

    private int $itemUomId;

    private int $currencyId;

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
                'name' => 'جبنة حلوم',
                'item_code' => 'CHEESE',
                'plu_code' => '01234',
                'is_scale_item' => true,
                'shelf_life_days' => 5,
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
            $this->currencyId = (int) $currency->id;
            $this->itemId = (string) $item->id;
            $this->itemUomId = (int) $itemUom->id;
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->tearDownTenant();
        parent::tearDown();
    }

    public function test_preview_rows_and_price_list_source(): void
    {
        $template = $this->createTemplate();

        $preview = $this->api('GET', "scale-export-templates/{$template}/preview");
        $preview->assertOk();
        $this->assertSame(['PLU', 'Name', 'Price', 'Type', 'Shelf'], $preview->json('data.headers'));
        $this->assertSame(['01234', 'جبنة حلوم', '10.00', 'W', '5'], $preview->json('data.rows.0'));
        $this->assertSame(1, $preview->json('data.item_count'));
        $this->assertSame(['code' => 'USD', 'symbol' => '$'], $preview->json('data.currency'));

        $listId = $this->tenant->run(function (): int {
            $euro = Currency::query()->create(['name' => 'Euro', 'code' => 'EUR', 'iso_code' => 'EUR', 'symbol' => '€', 'is_active' => true]);
            $list = PriceList::query()->create(['code' => 'DELI', 'name' => 'Deli', 'currency_id' => $euro->id, 'is_active' => true]);
            PriceListItem::query()->create([
                'price_list_id' => $list->id, 'item_id' => $this->itemId, 'item_uom_id' => $this->itemUomId, 'min_quantity' => 1, 'price' => '8.5',
            ]);

            return (int) $list->id;
        });
        $draft = $this->api('POST', 'scale-export-templates/preview', [
            ...$this->templateData(), 'price_source' => 'price_list', 'price_list_id' => $listId, 'decimal_separator' => ',',
        ]);
        $draft->assertOk();
        $this->assertSame('8,50', $draft->json('data.rows.0.2'));
        // Prices from a price list are in that list's currency.
        $this->assertSame(['code' => 'EUR', 'symbol' => '€'], $draft->json('data.currency'));
        $this->assertSame(['count' => 0, 'names' => []], $draft->json('data.missing_price'));
    }

    public function test_foreign_currency_list_leaves_out_items_without_a_list_price(): void
    {
        [$euroListId, $usdListId] = $this->tenant->run(function (): array {
            // A second scale item with only a selling price (USD), on no price list.
            $apples = Item::query()->whereKey($this->itemId)->firstOrFail()->replicate()->fill([
                'name' => 'Apples', 'item_code' => 'APPLES', 'plu_code' => '01300',
            ]);
            $apples->save();
            ItemUom::query()->create([
                'item_id' => $apples->id, 'uom_id' => $this->kgId, 'conversion_factor' => 1,
                'selling_price' => '2.30', 'is_base' => true, 'is_default_sale' => true,
            ]);

            $euro = Currency::query()->create(['name' => 'Euro', 'code' => 'EUR', 'iso_code' => 'EUR', 'symbol' => '€', 'is_active' => true]);
            $ids = [];
            foreach ([['EUR-DELI', $euro->id], ['USD-DELI', $this->currencyId]] as [$code, $currencyId]) {
                $list = PriceList::query()->create(['code' => $code, 'name' => $code, 'currency_id' => $currencyId, 'is_active' => true]);
                PriceListItem::query()->create([
                    'price_list_id' => $list->id, 'item_id' => $this->itemId, 'item_uom_id' => $this->itemUomId, 'min_quantity' => 1, 'price' => '8.5',
                ]);
                $ids[] = (int) $list->id;
            }

            return $ids;
        });

        // EUR list: Apples (USD selling price only) is left out and reported, not exported at 2.30.
        $euro = $this->api('POST', 'scale-export-templates/preview', [...$this->templateData(), 'price_source' => 'price_list', 'price_list_id' => $euroListId]);
        $euro->assertOk();
        $this->assertSame(1, $euro->json('data.item_count'));
        $this->assertSame(['count' => 1, 'names' => ['Apples']], $euro->json('data.missing_price'));
        $this->assertSame(['01234'], array_column($euro->json('data.rows'), 0));

        $templateId = $this->createTemplate(['price_source' => 'price_list', 'price_list_id' => $euroListId]);
        $card = collect($this->api('GET', 'scale-exports/outdated')->json('data'))->firstWhere('template_id', $templateId);
        $this->assertSame(1, $card['item_count']);
        $this->assertSame(1, $card['missing_price_count']);
        $this->assertStringNotContainsString('01300', (string) $this->api('POST', "scale-export-templates/{$templateId}/download")->getContent());

        // Same-currency list: Apples falls back to its selling price, as before.
        $usd = $this->api('POST', 'scale-export-templates/preview', [...$this->templateData(), 'price_source' => 'price_list', 'price_list_id' => $usdListId]);
        $this->assertSame(2, $usd->json('data.item_count'));
        $this->assertSame(0, $usd->json('data.missing_price.count'));
    }

    public function test_download_history_and_only_changed(): void
    {
        $start = Carbon::now()->addHour()->startOfMinute();
        Carbon::setTestNow($start);
        $template = $this->createTemplate();

        $status = $this->api('GET', 'scale-exports/outdated');
        $this->assertTrue($status->json('data.0.never_exported'));

        $file = $this->api('POST', "scale-export-templates/{$template}/download");
        $file->assertOk();
        $content = (string) $file->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFPLU;Name;Price;Type;Shelf\r\n01234;", $content);
        $this->assertStringContainsString('attachment; filename=', (string) $file->headers->get('Content-Disposition'));
        $this->assertSame(1, $this->tenant->run(fn () => ScaleExport::query()->count()));

        Carbon::setTestNow($start->copy()->addMinutes(5));
        $this->api('POST', "scale-export-templates/{$template}/download?only_changed=1")
            ->assertStatus(422)->assertJsonPath('code', 'SCALE_EXPORT_NOTHING_CHANGED');
        $this->assertSame(0, $this->api('GET', 'scale-exports/outdated')->json('data.0.changed_count'));

        Carbon::setTestNow($start->copy()->addMinutes(10));
        $this->api('PUT', "items/{$this->itemId}/item-uoms/{$this->itemUomId}", [
            'uom_id' => $this->kgId, 'conversion_factor' => 1, 'selling_price' => '11.00', 'is_base' => true, 'is_default_sale' => true,
        ])->assertOk();

        Carbon::setTestNow($start->copy()->addMinutes(15));
        $status = $this->api('GET', 'scale-exports/outdated');
        $this->assertSame(1, $status->json('data.0.changed_count'));
        $this->assertFalse($status->json('data.0.never_exported'));

        $changed = $this->api('POST', "scale-export-templates/{$template}/download?only_changed=1");
        $changed->assertOk();
        $this->assertStringContainsString('01234;', (string) $changed->getContent());
        $this->assertStringContainsString(';11.00;', (string) $changed->getContent());

        $history = $this->api('GET', 'scale-exports');
        $history->assertOk();
        $this->assertCount(2, $history->json('data.data'));
        $this->assertTrue($history->json('data.data.0.only_changed'));
        $detail = $this->api('GET', 'scale-exports/'.$history->json('data.data.0.id'));
        $this->assertSame('01234', $detail->json('data.items.0.plu_code'));

        Carbon::setTestNow($start->copy()->addMinutes(20));
        $this->assertSame(0, $this->api('GET', 'scale-exports/outdated')->json('data.0.changed_count'));
    }

    public function test_label_pdf_and_preview(): void
    {
        $formatId = $this->tenant->run(fn (): int => (int) app(ScaleBarcodeFormatService::class)->create([
            'name' => 'Weight label', 'engine' => 'fixed', 'symbology' => 'ean13', 'prefixes' => ['21'],
            'plu_start' => 3, 'plu_length' => 5, 'value_start' => 8, 'value_length' => 5,
            'value_type' => 'weight', 'decimals' => 3, 'uom_id' => $this->kgId,
        ])->id);

        $labelTemplate = [
            'name' => 'Deli 60x40', 'mode' => 'single', 'width_mm' => 60, 'height_mm' => 40,
            'fields' => ['company', 'name', 'unit_price', 'value', 'total', 'packed_date', 'use_by', 'barcode', 'barcode_text'],
            'font_size' => 8, 'scale_barcode_format_id' => $formatId,
        ];
        $created = $this->api('POST', 'scale-label-templates', $labelTemplate);
        $created->assertCreated();
        $templateId = (int) $created->json('data.id');

        $pdf = $this->api('POST', 'scale-labels/print', [
            'scale_label_template_id' => $templateId,
            'lines' => [['item_id' => $this->itemId, 'quantity' => '1.25', 'copies' => 2, 'packed_date' => '2026-11-20']],
        ]);
        $this->assertSame(200, $pdf->status(), (string) $pdf->getContent());
        $this->assertStringStartsWith('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        // Label prices carry the primary currency symbol.
        $label = $this->tenant->run(fn (): array => app(ScaleLabelService::class)->labels(
            ScaleLabelTemplate::query()->findOrFail($templateId),
            [['item_id' => $this->itemId, 'quantity' => '1.25', 'copies' => 1]],
        )[0]);
        $this->assertStringEndsWith(' $', (string) $label['unit_price']);
        $this->assertStringEndsWith(' $', (string) $label['total']);

        $sheet = $this->api('POST', 'scale-labels/preview', ['template' => [...$labelTemplate, 'mode' => 'sheet', 'sheet_columns' => 3, 'sheet_rows' => 8]]);
        $sheet->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $sheet->getContent());

        $this->api('POST', 'scale-labels/print', [
            'scale_label_template_id' => $templateId,
            'lines' => [['item_id' => $this->itemId, 'quantity' => '120', 'copies' => 1]],
        ])->assertStatus(422)->assertJsonPath('code', 'SCALE_LABEL_VALUE_TOO_BIG');
    }

    public function test_next_plu_check_and_format_warnings(): void
    {
        $this->assertSame('01235', $this->api('GET', 'items/next-plu')->json('data.plu'));

        // A full EAN typed as PLU on another item must not push the suggestion past 5 digits.
        $this->tenant->run(function (): void {
            Item::query()->whereKey($this->itemId)->firstOrFail()->replicate()->fill([
                'item_code' => 'LONG-PLU', 'plu_code' => '09501101530002',
            ])->save();
        });
        $this->assertSame('01235', $this->api('GET', 'items/next-plu')->json('data.plu'));

        $format = $this->api('POST', 'scale-barcode-formats', [
            'name' => 'Short PLU', 'engine' => 'fixed', 'symbology' => 'ean13', 'prefixes' => ['23'],
            'plu_start' => 3, 'plu_length' => 3, 'value_start' => 8, 'value_length' => 5,
            'value_type' => 'weight', 'decimals' => 3, 'uom_id' => $this->kgId,
        ]);
        $this->assertSame(201, $format->status(), (string) $format->getContent());
        $this->assertSame('01234', $format->json('data.warnings.plu_too_long.0.plu_code'));

        $check = $this->api('GET', 'items/plu-check?plu=1234');
        $this->assertFalse($check->json('data.fits'));
        $this->assertSame(3, $check->json('data.formats.0.plu_length'));
        $this->assertSame($this->itemId, $check->json('data.taken_by.id'));
        $this->assertNull($this->api('GET', 'items/plu-check?plu=1234&item_id='.$this->itemId)->json('data.taken_by'));
        $this->assertTrue($this->api('GET', 'items/plu-check?plu=12')->json('data.fits'));
    }

    public function test_export_and_print_need_permissions(): void
    {
        $template = $this->createTemplate();
        $viewer = $this->tenant->run(function (): string {
            $role = Role::query()->create(['name' => 'Nobody', 'is_active' => true]);
            $user = User::factory()->create(['email' => 'nobody@test.local', 'is_active' => true]);
            $user->branches()->attach((int) Branch::query()->orderBy('id')->value('id'), ['role_id' => $role->id]);

            return $user->createToken('tests')->plainTextToken;
        });

        $this->app['auth']->forgetGuards();
        $this->asTenantRequest($viewer)->postJson($this->tenantUrl("scale-export-templates/{$template}/download"))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->asTenantRequest($viewer)->getJson($this->tenantUrl('scale-exports'))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->asTenantRequest($viewer)->postJson($this->tenantUrl('scale-labels/print'), ['scale_label_template_id' => 1, 'lines' => []])->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(array $overrides = []): int
    {
        $created = $this->api('POST', 'scale-export-templates', [...$this->templateData(), ...$overrides]);
        $this->assertSame(201, $created->status(), (string) $created->getContent());

        return (int) $created->json('data.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function templateData(): array
    {
        return [
            'name' => 'Deli scale',
            'columns' => [
                ['field' => 'plu', 'header' => 'PLU'],
                ['field' => 'name', 'header' => 'Name'],
                ['field' => 'price', 'header' => 'Price'],
                ['field' => 'price_type', 'header' => 'Type', 'map' => ['weight' => 'W', 'piece' => 'P']],
                ['field' => 'shelf_life_days', 'header' => 'Shelf'],
            ],
            'delimiter' => ';',
            'has_header' => true,
            'encoding' => 'utf8_bom',
            'line_ending' => 'crlf',
            'quote' => 'needed',
            'file_extension' => 'csv',
            'decimal_separator' => '.',
            'price_decimals' => 2,
            'price_source' => 'item_price',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function api(string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->asTenantRequest($this->token)->json($method, $this->tenantUrl($path), $data);
    }
}
