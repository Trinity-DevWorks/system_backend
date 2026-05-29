<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Support\ItemDeleteRules;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ItemService
{
    public function __construct(
        private readonly ItemUomService $itemUomService
    ) {}

    public function list(): Collection
    {
        return Item::query()
            ->with([
                'itemType:id,code,name',
                'category:id,code,name',
                'brand:id,code,name',
                'baseUom:id,code,name,unit_group_id',
                'vatGroup:id,abrv,name,percentage',
                'primaryImageAttachment:id,attachable_type,attachable_id,viewer_category,is_primary',
            ])
            ->orderBy('name')
            ->get();
    }

    public function create(ItemData $data): Item
    {
        return DB::transaction(function () use ($data): Item {
            $item = Item::query()->create($data->toArray());

            if ($data->trackInventory && $data->baseUomId) {
                $this->ensureBaseItemUom($item);
            }

            return $item->load(['itemType', 'category', 'brand', 'baseUom', 'vatGroup']);
        });
    }

    public function update(Item $item, ItemData $data): Item
    {
        return DB::transaction(function () use ($item, $data): Item {
            $oldBaseId = $item->base_uom_id;

            if (! $data->trackInventory) {
                ItemUom::query()->where('item_id', $item->id)->delete();
            }

            $item->update($data->toArray());
            $item->refresh();

            if ($data->trackInventory && $data->baseUomId) {
                $newBase = UnitOfMeasurement::query()->findOrFail($data->baseUomId);
                $this->assertPivotsMatchBaseGroup($item, $newBase);

                if ($oldBaseId !== null && (int) $oldBaseId !== (int) $data->baseUomId) {
                    ItemUom::query()
                        ->where('item_id', $item->id)
                        ->where('uom_id', $oldBaseId)
                        ->delete();
                }

                $this->ensureBaseItemUom($item);
            }

            return $item->load(['itemType', 'category', 'brand', 'baseUom', 'vatGroup']);
        });
    }

    public function delete(Item $item): void
    {
        ItemDeleteRules::assertDeletable($item);
        $item->delete();
    }

    private function ensureBaseItemUom(Item $item): void
    {
        $primary = Currency::getPrimary();
        if (! $primary) {
            abort(422, 'Set a primary currency before creating stockable items.', ['X-Error-Code' => 'PRIMARY_CURRENCY_REQUIRED']);
        }

        $this->itemUomService->ensureBaseRow($item, (int) $primary->id);
    }

    private function assertPivotsMatchBaseGroup(Item $item, UnitOfMeasurement $newBase): void
    {
        $pivots = ItemUom::query()
            ->where('item_id', $item->id)
            ->with('uom:id,unit_group_id')
            ->get();

        foreach ($pivots as $pivot) {
            $uom = $pivot->uom;
            if ($uom && (int) $uom->unit_group_id !== (int) $newBase->unit_group_id) {
                abort(422, 'Cannot change base UOM: existing alternate UOMs belong to a different unit group.', ['X-Error-Code' => 'ITEM_BASE_UOM_CHANGE_GROUP_MISMATCH']);
            }
        }
    }
}
