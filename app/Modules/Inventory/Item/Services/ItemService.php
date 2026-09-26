<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Item\Support\ItemDeleteRules;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Support\ListPagination;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
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

    public function paginateForTable(?string $search, int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = Item::query()
            ->with([
                'itemType:id,code,name',
                'category:id,code,name,parent_id',
                'brand:id,code,name',
                'unitGroup:id,code,name',
                'baseUom:id,code,name,unit_group_id',
                'vatGroup:id,abrv,name,percentage',
                'primaryImageAttachment:id,attachable_type,attachable_id,viewer_category,is_primary',
            ])
            ->orderBy('name');

        if (array_key_exists('allow_sale', $filters) && $filters['allow_sale'] !== null) {
            $query->where('allow_sale', (bool) $filters['allow_sale']);
        }
        if (array_key_exists('allow_purchase', $filters) && $filters['allow_purchase'] !== null) {
            $query->where('allow_purchase', (bool) $filters['allow_purchase']);
        }
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        ListPagination::applySearch(
            $query,
            $search,
            ['sku', 'item_code', 'plu_code', 'name'],
            [
                'category' => ['code', 'name'],
                'brand' => ['code', 'name'],
                'itemType' => ['code', 'name'],
                'barcodes' => ['barcode'],
                'itemUoms' => ['barcode'],
            ],
        );

        return $query->paginate($perPage);
    }

    /**
     * Slim paginated items for sales/purchase invoice typeahead.
     *
     * @param  'sale'|'purchase'  $context
     */
    public function paginateForInvoice(string $context, ?string $search, int $perPage): LengthAwarePaginator
    {
        $query = Item::query()
            ->select([
                'id',
                'item_code',
                'name',
                'vat_group_id',
                'track_inventory',
                'track_lots',
                'allow_sale',
                'allow_purchase',
                'is_active',
            ])
            ->with(['vatGroup:id,percentage'])
            ->where('is_active', true)
            ->orderBy('name');

        if ($context === 'purchase') {
            $query->where('allow_purchase', true);
        } else {
            $query->where('allow_sale', true);
        }

        ListPagination::applySearch(
            $query,
            $search,
            ['sku', 'item_code', 'plu_code', 'name'],
            [
                'barcodes' => ['barcode'],
                'itemUoms' => ['barcode'],
            ],
        );

        return $query->paginate($perPage);
    }

    /**
     * Load UOMs + barcodes for one invoice line item (single round-trip).
     */
    public function findForInvoiceLineSetup(Item $item): Item
    {
        return $item->load([
            'itemUoms.uom:id,code,name,unit_group_id',
            'itemUoms.barcodes:id,item_uom_id,barcode,is_primary',
            'barcodes:id,item_id,item_uom_id,barcode,is_primary',
        ]);
    }

    /**
     * Lightweight lookup rows for selects (bundle, recipe, stock drawers).
     *
     * @return Collection<int, Item>
     */
    public function names(): Collection
    {
        return Item::query()
            ->select(['id', 'item_code', 'name', 'item_type_id', 'base_uom_id', 'vat_group_id', 'track_inventory', 'track_lots', 'allow_sale', 'allow_purchase', 'is_active'])
            ->with(['itemType:id,code,name', 'baseUom:id,code,name', 'vatGroup:id,percentage'])
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

            if ($data->trackLots && ! $item->track_lots) {
                $hasUnlottedQty = StockBalance::query()
                    ->where('item_id', $item->id)
                    ->whereNull('lot_id')
                    ->where('quantity', '>', 0)
                    ->exists();
                if ($hasUnlottedQty) {
                    abort(422, 'Zero unlotted stock before enabling lot tracking.', [
                        'X-Error-Code' => 'ITEM_LOTS_ENABLE_HAS_STOCK',
                    ]);
                }
            }

            if (! $data->trackLots && $item->track_lots) {
                $hasLottedQty = StockBalance::query()
                    ->where('item_id', $item->id)
                    ->whereNotNull('lot_id')
                    ->where('quantity', '>', 0)
                    ->exists();
                if ($hasLottedQty) {
                    abort(422, 'Zero lotted stock before disabling lot tracking.', [
                        'X-Error-Code' => 'ITEM_LOTS_DISABLE_HAS_STOCK',
                    ]);
                }
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
