<?php

namespace App\Modules\Inventory\Item\Services;

use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Support\BundleItemRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class BundleItemService
{
    public function listForBundle(Item $bundle): Collection
    {
        BundleItemRules::assertIsBundleItem($bundle);

        return BundleItem::query()
            ->where('bundle_item_id', $bundle->id)
            ->with(['childItem.itemType:id,code,name'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{child_item_id:int,quantity:numeric}  $data
     */
    public function addComponent(Item $bundle, array $data): BundleItem
    {
        BundleItemRules::assertIsBundleItem($bundle);

        $child = Item::query()->findOrFail($data['child_item_id']);
        BundleItemRules::assertValidChild($bundle, $child);

        return DB::transaction(function () use ($bundle, $data): BundleItem {
            return BundleItem::query()->create([
                'bundle_item_id' => $bundle->id,
                'child_item_id' => $data['child_item_id'],
                'quantity' => number_format((float) $data['quantity'], 6, '.', ''),
            ])->load(['childItem.itemType']);
        });
    }

    public function updateQuantity(Item $bundle, BundleItem $row, string $quantity): BundleItem
    {
        $this->assertScoped($bundle, $row);

        $row->update([
            'quantity' => number_format((float) $quantity, 6, '.', ''),
        ]);

        return $row->refresh()->load(['childItem.itemType']);
    }

    public function removeComponent(Item $bundle, BundleItem $row): void
    {
        $this->assertScoped($bundle, $row);
        $row->delete();
    }

    /**
     * Replace all bundle components in one request.
     *
     * @param  list<array{child_item_id:int,quantity:numeric}>  $components
     */
    public function sync(Item $bundle, array $components): Collection
    {
        BundleItemRules::assertIsBundleItem($bundle);

        return DB::transaction(function () use ($bundle, $components): Collection {
            $lines = [];
            foreach ($components as $row) {
                $childId = (string) $row['child_item_id'];
                $lines[$childId] = number_format((float) $row['quantity'], 6, '.', '');
            }

            foreach (array_keys($lines) as $childId) {
                $child = Item::query()->findOrFail($childId);
                BundleItemRules::assertValidChild($bundle, $child);
            }

            BundleItem::query()->where('bundle_item_id', $bundle->id)->delete();

            foreach ($lines as $childId => $quantity) {
                BundleItem::query()->create([
                    'bundle_item_id' => $bundle->id,
                    'child_item_id' => $childId,
                    'quantity' => $quantity,
                ]);
            }

            return $this->listForBundle($bundle);
        });
    }

    /**
     * Components for sale-time stock explosion (base UOM quantities).
     *
     * @return Collection<int, BundleItem>
     */
    public function componentsForExplosion(Item $bundle): Collection
    {
        return $this->listForBundle($bundle);
    }

    private function assertScoped(Item $bundle, BundleItem $row): void
    {
        BundleItemRules::assertIsBundleItem($bundle);

        if ((string) $row->bundle_item_id !== (string) $bundle->id) {
            abort(404, 'Bundle component not found for this item.', ['X-Error-Code' => 'BUNDLE_ITEM_SCOPE_MISMATCH']);
        }
    }
}
