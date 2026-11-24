<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Category\Models\Category;
use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemBarcodeService;
use App\Modules\Inventory\Item\Services\ItemService;
use App\Modules\Inventory\Item\Services\ItemUomService;
use App\Modules\Inventory\ItemType\Models\ItemType;
use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Console\Command;
use Throwable;

/**
 * Test data only: creates scale items (PLU, units, prices, barcodes) through the normal item
 * services, so every item rule still applies. Items get the code prefix SCL- and are skipped
 * when they already exist; --fresh deletes them first.
 */
class SeedScaleTestItems extends Command
{
    protected $signature = 'scale:seed-test-items {tenant=tenant : Tenant id} {--fresh : Delete the SCL- test items first}';

    protected $description = 'Create test items sold at the scale (weighed and per piece) with PLUs, prices and barcodes';

    /**
     * [code, name, arabic name, plu, kind (weight|piece), price per kg / per piece, GTIN barcode or null, shelf life days]
     *
     * @var list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:?string,7:?int}>
     */
    private const ITEMS = [
        ['SCL-001', 'Chicken breast', 'صدر دجاج', '1001', 'weight', '8.50', null, 3],
        ['SCL-002', 'Minced beef', 'لحم مفروم', '1002', 'weight', '14.00', null, 2],
        ['SCL-003', 'Lamb chops', 'ريش غنم', '1003', 'weight', '22.00', null, 3],
        ['SCL-004', 'White cheese', 'جبنة بيضاء', '2001', 'weight', '9.75', null, 10],
        ['SCL-005', 'Halloumi', 'حلوم', '2002', 'weight', '12.40', null, 20],
        ['SCL-006', 'Turkey slices', 'شرائح حبش', '2003', 'weight', '11.20', null, 7],
        ['SCL-007', 'Tomatoes', 'بندورة', '3001', 'weight', '1.20', null, 5],
        ['SCL-008', 'Cucumbers', 'خيار', '3002', 'weight', '1.10', null, 5],
        ['SCL-009', 'Bananas', 'موز', '3003', 'weight', '1.60', null, 6],
        ['SCL-010', 'Apples red', 'تفاح أحمر', '3004', 'weight', '2.30', null, 14],
        ['SCL-011', 'Mixed nuts', 'مكسرات مشكلة', '4001', 'weight', '18.00', null, 60],
        ['SCL-012', 'Olives green', 'زيتون أخضر', '4002', 'weight', '6.50', null, 30],
        ['SCL-013', 'Croissant', 'كرواسان', '5001', 'piece', '0.90', null, 2],
        ['SCL-014', 'Manakish zaatar', 'منقوشة زعتر', '5002', 'piece', '1.50', null, 1],
        ['SCL-015', 'Kaak', 'كعك', '5003', 'piece', '0.75', null, 3],
        // GS1-128 test item: GTIN saved as the barcode → test (01)09501101530003(3103)001250 with empty PLU positions.
        ['SCL-016', 'GS1 test salmon', 'سلمون', '6001', 'weight', '25.00', '09501101530003', 4],
    ];

    public function handle(ItemService $items, ItemUomService $uoms, ItemBarcodeService $barcodes): int
    {
        $tenant = Tenant::query()->find((string) $this->argument('tenant'));
        if ($tenant === null) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $tenant->run(function () use ($items, $uoms, $barcodes): void {
            if ($this->option('fresh')) {
                $old = Item::query()->withTrashed()->where('item_code', 'like', 'SCL-%')->get();
                $old->each(fn (Item $item) => $item->forceDelete());
                $this->info("Deleted {$old->count()} test item(s).");
            }

            $type = ItemType::query()->where('code', 'INVENTORY')->firstOrFail();
            $weightGroup = UnitGroup::query()->where('code', 'WEIGHT')->firstOrFail();
            $countGroup = UnitGroup::query()->where('code', 'COUNT')->firstOrFail();
            $kg = UnitOfMeasurement::query()->where('code', 'KG')->firstOrFail();
            $g = UnitOfMeasurement::query()->where('code', 'G')->firstOrFail();
            $ea = UnitOfMeasurement::query()->where('code', 'EA')->firstOrFail();
            $vatId = VatGroup::query()->orderBy('id')->value('id');
            $categoryId = $this->categoryId();

            $created = 0;
            foreach (self::ITEMS as [$code, $name, $arabic, $plu, $kind, $price, $gtin, $shelfLife]) {
                if (Item::query()->withTrashed()->where('item_code', $code)->orWhere('plu_code', $plu)->exists()) {
                    $this->line("skip  {$code} (exists, or PLU {$plu} is taken)");

                    continue;
                }

                try {
                    $isWeight = $kind === 'weight';
                    $item = $items->create(new ItemData(
                        name: $name,
                        sku: null,
                        itemCode: $code,
                        pluCode: $plu,
                        itemTypeId: (int) $type->id,
                        categoryId: $categoryId,
                        brandId: null,
                        unitGroupId: (int) ($isWeight ? $weightGroup->id : $countGroup->id),
                        vatGroupId: $vatId !== null ? (int) $vatId : null,
                        description: 'Scale test item',
                        ticketName: null,
                        kitchenName: null,
                        sendToKitchen: false,
                        qrEnabled: false,
                        qrDescription: null,
                        posName: $arabic,
                        color: null,
                        trackInventory: true,
                        trackLots: false,
                        costingMethod: null,
                        allowSale: true,
                        allowPurchase: true,
                        allowReturn: true,
                        isActive: true,
                        isScaleItem: true,
                        shelfLifeDays: $shelfLife,
                    ));

                    if ($isWeight) {
                        // KG is the base and sale unit; G lets "grams" label formats work too.
                        $uoms->create($item, ['uom_id' => $kg->id, 'selling_price' => $price, 'cost_price' => bcmul($price, '0.6', 2), 'is_default_sale' => true]);
                        $uoms->create($item, ['uom_id' => $g->id, 'conversion_factor' => '0.001', 'selling_price' => bcdiv($price, '1000', 6), 'is_default_sale' => false, 'is_default_purchase' => false]);
                    } else {
                        $uoms->create($item, ['uom_id' => $ea->id, 'selling_price' => $price, 'cost_price' => bcmul($price, '0.6', 2), 'is_default_sale' => true]);
                    }

                    $barcode = $gtin ?? null;
                    if ($barcode !== null) {
                        $barcodes->store($item, ['barcode' => $barcode, 'is_primary' => true, 'item_uom_id' => null]);
                    } else {
                        $barcodes->generate($item, null);
                    }

                    $created++;
                    $this->line("added {$code}  PLU {$plu}  {$name}  {$price} / ".($isWeight ? 'kg' : 'piece'));
                } catch (Throwable $e) {
                    $this->error("fail  {$code}: {$e->getMessage()}");
                }
            }
            $this->info("Done. {$created} item(s) created.");
        });

        return self::SUCCESS;
    }

    private function categoryId(): ?int
    {
        try {
            return (int) Category::query()->firstOrCreate(['name' => 'Scale test items'])->id;
        } catch (Throwable) {
            return null;
        }
    }
}
