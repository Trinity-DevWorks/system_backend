<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Notification\Services\InstantLotExpiryNotifier;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ListPagination;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class InventoryLotService
{
    public function __construct(
        private readonly WarehouseService $warehouseService,
        private readonly InstantLotExpiryNotifier $instantLotExpiryNotifier,
    ) {}

    /**
     * Resolve the lot for a stock movement. Null when the item does not track lots.
     */
    public function resolve(
        Item $item,
        ?int $lotId,
        ?string $lotNumber,
        ?string $expiryDate,
        bool $inbound,
    ): ?InventoryLot {
        $number = self::normalizeLotNumber($lotNumber);

        if (! $item->track_lots) {
            if ($lotId !== null || $number !== null) {
                abort(422, 'This item does not track lots.', ['X-Error-Code' => 'STOCK_LOT_NOT_TRACKED']);
            }

            return null;
        }

        if ($lotId !== null) {
            $lot = InventoryLot::query()
                ->where('item_id', $item->id)
                ->whereKey($lotId)
                ->first();

            if ($lot === null) {
                abort(422, 'The selected lot does not belong to this item.', ['X-Error-Code' => 'STOCK_LOT_MISMATCH']);
            }

            $this->syncExpiry($lot, $expiryDate);

            return $lot;
        }

        if ($number === null) {
            abort(
                422,
                $inbound ? 'Enter a lot number when adding stock for a lot-tracked item.' : 'Select a lot when issuing stock for a lot-tracked item.',
                ['X-Error-Code' => 'STOCK_LOT_REQUIRED'],
            );
        }

        if ($inbound) {
            return $this->findOrCreate($item, $number, $expiryDate);
        }

        $lot = InventoryLot::query()
            ->where('item_id', $item->id)
            ->where('lot_number', $number)
            ->first();

        if ($lot === null) {
            abort(422, 'Lot was not found for this item.', ['X-Error-Code' => 'STOCK_LOT_NOT_FOUND']);
        }

        return $lot;
    }

    public function findOrCreate(Item $item, string $lotNumber, ?string $expiryDate): InventoryLot
    {
        $lot = InventoryLot::query()->firstOrCreate(
            [
                'item_id' => $item->id,
                'lot_number' => $lotNumber,
            ],
            [
                'expiry_date' => self::normalizeExpiry($expiryDate),
            ]
        );

        $this->syncExpiry($lot, $expiryDate);

        return $lot->refresh();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function listForItemWarehouse(string $itemId, ?int $warehouseId): Collection
    {
        $item = Item::query()->findOrFail($itemId);
        if ($warehouseId !== null) {
            $this->warehouseService->assertVisibleById($warehouseId);
        }

        $qtyByLotId = StockBalance::query()
            ->selectRaw('lot_id, SUM(quantity) as quantity')
            ->where('item_id', $item->id)
            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->whereNotNull('lot_id')
            ->groupBy('lot_id')
            ->pluck('quantity', 'lot_id');

        /** @var Collection<int, array<string, mixed>> $lots */
        $lots = InventoryLot::query()
            ->where('item_id', $item->id)
            ->orderByRaw('expiry_date is null')
            ->orderBy('expiry_date')
            ->orderBy('lot_number')
            ->get()
            ->map(function (InventoryLot $lot) use ($qtyByLotId): array {
                return [
                    'id' => $lot->id,
                    'item_id' => $lot->item_id,
                    'lot_number' => $lot->lot_number,
                    'expiry_date' => $lot->expiry_date?->toDateString(),
                    'is_expired' => $lot->isExpired(),
                    'quantity' => isset($qtyByLotId[$lot->id])
                        ? number_format((float) $qtyByLotId[$lot->id], 6, '.', '')
                        : '0.000000',
                ];
            })
            ->values();

        return $lots;
    }

    /**
     * One row per lot at a warehouse (stock balance with a lot).
     *
     * @param  array{
     *   warehouse_id?:int|null,
     *   item_id?:string|null,
     *   search?:string|null,
     *   expired?:bool,
     *   missing_expiry?:bool,
     *   only_with_stock?:bool
     * }  $filters
     * @return LengthAwarePaginator<int, StockBalance>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $warehouseId = ! empty($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        if ($warehouseId !== null) {
            $this->warehouseService->assertVisibleById($warehouseId);
        }

        $query = StockBalance::query()
            ->with([
                'item:id,item_code,name',
                'warehouse:id,name,shortcut_name,is_active',
                'lot:id,lot_number,expiry_date,item_id',
            ])
            ->whereNotNull('lot_id');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        if (! empty($filters['item_id'])) {
            $query->where('item_id', (string) $filters['item_id']);
        }

        ListPagination::applySearch(
            $query,
            $filters['search'] ?? null,
            [],
            [
                'lot' => ['lot_number'],
                'item' => ['name', 'item_code'],
                'warehouse' => ['name', 'shortcut_name'],
            ]
        );

        if (! empty($filters['expired'])) {
            $query->whereHas('lot', function ($q): void {
                $q->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<', now()->toDateString());
            });
        }

        if (! empty($filters['missing_expiry'])) {
            $query->whereHas('lot', function ($q): void {
                $q->whereNull('expiry_date');
            });
        }

        if (! empty($filters['only_with_stock'])) {
            $query->where('quantity', '>', 0);
        }

        $lotNumberSub = InventoryLot::query()
            ->select('lot_number')
            ->whereColumn('inventory_lots.id', 'stock_balances.lot_id')
            ->limit(1);

        /** @var LengthAwarePaginator<int, StockBalance> $paginator */
        $paginator = $query
            ->orderBy($lotNumberSub)
            ->orderBy('warehouse_id')
            ->paginate($perPage);

        return $paginator;
    }

    /**
     * On-hand lot balances that expire on or before $untilDate (YYYY-MM-DD), including already expired.
     * Unscoped by the current user's warehouse visibility — for scheduled digests.
     *
     * @return list<array{
     *   lot_id:int,
     *   item_id:string,
     *   warehouse_id:int,
     *   lot_number:string,
     *   expiry_date:string,
     *   quantity:string,
     *   is_expired:bool
     * }>
     */
    public function listOnHandExpiringOnOrBefore(string $untilDate): array
    {
        $today = now()->toDateString();

        $rows = StockBalance::query()
            ->with(['lot:id,lot_number,expiry_date'])
            ->whereNotNull('lot_id')
            ->where('quantity', '>', 0)
            ->whereHas('lot', function ($query) use ($untilDate): void {
                $query->whereNotNull('expiry_date')
                    ->whereDate('expiry_date', '<=', $untilDate);
            })
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $lot = $row->lot;
            if ($lot === null || $lot->expiry_date === null) {
                continue;
            }

            $expiry = $lot->expiry_date->toDateString();
            $out[] = [
                'lot_id' => (int) $lot->id,
                'item_id' => (string) $row->item_id,
                'warehouse_id' => (int) $row->warehouse_id,
                'lot_number' => (string) $lot->lot_number,
                'expiry_date' => $expiry,
                'quantity' => number_format((float) $row->quantity, 6, '.', ''),
                'is_expired' => $expiry < $today,
            ];
        }

        return $out;
    }

    public function updateExpiry(InventoryLot $lot, ?string $expiryDate): InventoryLot
    {
        $lot->update(['expiry_date' => self::normalizeExpiry($expiryDate)]);
        $updated = $lot->fresh() ?? $lot;
        $this->instantLotExpiryNotifier->afterExpiryChanged($updated);

        return $updated;
    }

    private function syncExpiry(InventoryLot $lot, ?string $expiryDate): void
    {
        $normalized = self::normalizeExpiry($expiryDate);
        if ($normalized === null) {
            return;
        }

        if ($lot->expiry_date === null) {
            $lot->update(['expiry_date' => $normalized]);

            return;
        }

        if ($lot->expiry_date->toDateString() !== $normalized) {
            abort(422, 'Expiry date does not match this lot.', ['X-Error-Code' => 'STOCK_LOT_EXPIRY_MISMATCH']);
        }
    }

    public static function normalizeLotNumber(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtoupper(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    public static function normalizeExpiry(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value)->toDateString();
    }
}
