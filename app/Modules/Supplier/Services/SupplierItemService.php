<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\DTOs\SupplierItemData;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SupplierItemService
{
    public function listForSupplier(Supplier $supplier): Collection
    {
        return SupplierItem::query()
            ->where('supplier_id', $supplier->id)
            ->with([
                'item:id,sku,name,allow_purchase,is_active',
                'currency:id,code,name,symbol,iso_code',
            ])
            ->orderByDesc('is_preferred')
            ->orderBy('id')
            ->get();
    }

    public function listForItem(Item $item): Collection
    {
        return SupplierItem::query()
            ->where('item_id', $item->id)
            ->with([
                'supplier:id,supplier_code,name,is_active',
                'currency:id,code,name,symbol,iso_code',
            ])
            ->orderByDesc('is_preferred')
            ->orderBy('id')
            ->get();
    }

    public function create(Supplier $supplier, SupplierItemData $data): SupplierItem
    {
        $this->assertActiveSupplier($supplier);
        $this->assertPurchasableItem($data->itemId);

        return DB::transaction(function () use ($supplier, $data): SupplierItem {
            if ($data->isPreferred) {
                $this->clearPreferredForItem($data->itemId);
            }

            return SupplierItem::query()->create([
                'supplier_id' => $supplier->id,
                ...$data->toArray(),
            ])->load(['item', 'currency']);
        });
    }

    public function update(SupplierItem $row, SupplierItemData $data): SupplierItem
    {
        return DB::transaction(function () use ($row, $data): SupplierItem {
            if ($data->isPreferred && ! $row->is_preferred) {
                $this->clearPreferredForItem($row->item_id, $row->id);
            }

            $row->update($data->toArray());

            return $row->refresh()->load(['item', 'currency', 'supplier']);
        });
    }

    public function delete(SupplierItem $row): void
    {
        $row->delete();
    }

    /**
     * Update last purchase price snapshot (call from purchase receipt flow later).
     */
    public function recordLastPurchasePrice(SupplierItem $row, string $price, int $currencyId): SupplierItem
    {
        $row->update([
            'last_purchase_price' => number_format((float) $price, 4, '.', ''),
            'currency_id' => $currencyId,
        ]);

        return $row->refresh();
    }

    private function assertActiveSupplier(Supplier $supplier): void
    {
        if (! $supplier->is_active) {
            abort(422, 'Cannot link items to an inactive supplier.', ['X-Error-Code' => 'SUPPLIER_INACTIVE']);
        }
    }

    private function assertPurchasableItem(string $itemId): void
    {
        $item = Item::query()->findOrFail($itemId);

        if (! $item->allow_purchase) {
            abort(422, 'This item is not enabled for purchasing.', ['X-Error-Code' => 'ITEM_PURCHASE_NOT_ALLOWED']);
        }

        if (! $item->is_active) {
            abort(422, 'Cannot link an inactive item.', ['X-Error-Code' => 'ITEM_INACTIVE']);
        }
    }

    private function clearPreferredForItem(string $itemId, ?int $exceptId = null): void
    {
        $query = SupplierItem::query()->where('item_id', $itemId);
        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }
        $query->update(['is_preferred' => false]);
    }
}
