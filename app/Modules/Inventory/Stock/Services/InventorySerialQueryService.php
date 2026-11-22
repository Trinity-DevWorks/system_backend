<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Stock\Models\InventorySerial;
use App\Modules\Inventory\Stock\Models\InventorySerialMovement;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ListPagination;
use App\Support\TenantClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read side of serial numbers: the serials list, the picker, one unit with its history.
 */
class InventorySerialQueryService
{
    private const RELATIONS = [
        'item:id,item_code,name,warranty_months',
        'warehouse:id,name,shortcut_name',
        'customer:id,name,customer_code',
        'supplier:id,name,supplier_code',
    ];

    public function __construct(
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, InventorySerial>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = InventorySerial::query()->with(self::RELATIONS);
        $this->applyVisibility($query);

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (string) $filters['item_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $this->warehouseService->assertVisibleById((int) $filters['warehouse_id']);
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }
        if (! empty($filters['status'])) {
            $statuses = array_values(array_intersect((array) $filters['status'], InventorySerial::STATUSES));
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (string) $filters['supplier_id']);
        }
        $today = TenantClock::today()->toDateString();
        if (($filters['warranty'] ?? null) === 'active') {
            $query->whereNotNull('warranty_ends_on')->whereDate('warranty_ends_on', '>=', $today);
        } elseif (($filters['warranty'] ?? null) === 'expired') {
            $query->whereNotNull('warranty_ends_on')->whereDate('warranty_ends_on', '<', $today);
        }

        ListPagination::applySearch(
            $query,
            $filters['search'] ?? null,
            ['serial_number'],
            ['item' => ['name', 'item_code'], 'customer' => ['name']],
        );

        $sort = (string) ($filters['sort'] ?? '');
        match ($sort) {
            'serial_number' => $query->orderBy('serial_number'),
            'received_at' => $query->orderByDesc('received_at'),
            'sold_at' => $query->orderByDesc('sold_at'),
            'warranty_ends_on' => $query->orderBy('warranty_ends_on'),
            default => $query->orderByDesc('updated_at'),
        };
        $query->orderByDesc('id');

        return $query->paginate($perPage);
    }

    /**
     * In-stock serials of an item in a warehouse, for picking on outbound lines.
     *
     * @return list<array{id: int, serial_number: string}>
     */
    public function available(string $itemId, int $warehouseId, ?string $search, int $limit = 200): array
    {
        $this->warehouseService->assertVisibleById($warehouseId);
        $query = InventorySerial::query()
            ->where('item_id', $itemId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', InventorySerial::STATUS_IN_STOCK)
            ->orderBy('received_at')
            ->orderBy('serial_number');
        $term = $search !== null ? mb_strtoupper(trim($search)) : '';
        if ($term !== '') {
            $query->where('serial_number', 'like', '%'.addcslashes($term, '%_\\').'%');
        }

        return $query->limit($limit)->get(['id', 'serial_number'])
            ->map(fn (InventorySerial $s): array => ['id' => (int) $s->id, 'serial_number' => $s->serial_number])
            ->values()
            ->all();
    }

    public function find(int $id): InventorySerial
    {
        $query = InventorySerial::query()->with(self::RELATIONS)->whereKey($id);
        $this->applyVisibility($query);

        return $query->firstOrFail();
    }

    /**
     * @return Collection<int, InventorySerialMovement>
     */
    public function history(InventorySerial $serial): Collection
    {
        return InventorySerialMovement::query()
            ->with(['warehouse:id,name,shortcut_name', 'user:id,name'])
            ->where('inventory_serial_id', $serial->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A user sees units whose (last) warehouse they can see.
     *
     * @param  Builder<InventorySerial>  $query
     */
    private function applyVisibility(Builder $query): void
    {
        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');
    }
}
