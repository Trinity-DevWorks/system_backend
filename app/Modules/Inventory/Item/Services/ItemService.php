<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Support\ItemDeleteRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ItemService
{
    public function list(): Collection
    {
        return Item::query()
            ->with([
                'itemType:id,code,name',
                'category:id,code,name,parent_id',
                'brand:id,code,name',
                'unitGroup:id,code,name',
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

            return $item->load(['itemType', 'category', 'brand', 'unitGroup', 'baseUom', 'vatGroup']);
        });
    }

    public function update(Item $item, ItemData $data): Item
    {
        return DB::transaction(function () use ($item, $data): Item {
            if (! $data->trackInventory) {
                ItemUom::query()->where('item_id', $item->id)->delete();
                $item->base_uom_id = null;
            }

            if (
                $data->unitGroupId !== (int) $item->unit_group_id
                && ItemUom::query()->where('item_id', $item->id)->exists()
            ) {
                abort(
                    422,
                    'Cannot change unit group while item units exist. Remove all units first.',
                    ['X-Error-Code' => 'ITEM_UNIT_GROUP_CHANGE_FORBIDDEN'],
                );
            }

            $item->update($data->toArray());

            return $item->load(['itemType', 'category', 'brand', 'unitGroup', 'baseUom', 'vatGroup']);
        });
    }

    public function delete(Item $item): void
    {
        ItemDeleteRules::assertDeletable($item);
        $item->delete();
    }
}
