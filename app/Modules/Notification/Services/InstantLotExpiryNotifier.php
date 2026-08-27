<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Notification\Support\RecipientQuery;
use App\Modules\Warehouse\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Publishes one immediate alert when on-hand stock is received (or expiry is
 * set) on a lot that is already expired or expires within the configured window.
 * Further instant alerts for the same lot×warehouse stay suppressed until the
 * daily digest covers them or the lot moves out of the window.
 */
final class InstantLotExpiryNotifier
{
    public const STATUS_OK = 'ok';

    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_EXPIRED = 'expired';

    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function afterInboundLot(
        Item $item,
        Warehouse $warehouse,
        int $lotId,
        string $onHandQuantity,
    ): void {
        if (bccomp($onHandQuantity, '0', 6) <= 0) {
            return;
        }

        $lot = InventoryLot::query()->whereKey($lotId)->first();
        if ($lot === null) {
            return;
        }

        $this->notifyIfInWindow($item, $warehouse, $lot);
    }

    public function afterExpiryChanged(InventoryLot $lot): void
    {
        $lot->loadMissing(['item:id,item_code,name']);
        $item = $lot->item;
        if ($item === null) {
            return;
        }

        $status = self::alertStatus($lot->expiry_date?->toDateString());
        if ($status === self::STATUS_OK) {
            $this->forgetLot($lot);

            return;
        }

        $balances = StockBalance::query()
            ->with(['warehouse:id,name,branch_id'])
            ->where('lot_id', $lot->id)
            ->where('quantity', '>', 0)
            ->get();

        foreach ($balances as $balance) {
            $warehouse = $balance->warehouse;
            if ($warehouse === null) {
                continue;
            }

            $this->notifyIfInWindow($item, $warehouse, $lot);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function releaseAfterDigest(array $rows): void
    {
        foreach ($rows as $row) {
            $lotId = isset($row['lot_id']) ? (int) $row['lot_id'] : 0;
            $warehouseId = isset($row['warehouse_id']) ? (int) $row['warehouse_id'] : 0;

            if ($lotId > 0 && $warehouseId > 0) {
                Cache::forget($this->suppressionKey($lotId, $warehouseId));
            }
        }
    }

    public static function alertStatus(?string $expiryDate, ?string $today = null, ?int $withinDays = null): string
    {
        if ($expiryDate === null || $expiryDate === '') {
            return self::STATUS_OK;
        }

        $today ??= now()->toDateString();
        $withinDays ??= max(1, (int) config('notifications.lot_expiry.within_days', 7));
        $until = Carbon::parse($today)->addDays($withinDays)->toDateString();

        if ($expiryDate < $today) {
            return self::STATUS_EXPIRED;
        }

        if ($expiryDate <= $until) {
            return self::STATUS_EXPIRING;
        }

        return self::STATUS_OK;
    }

    private function notifyIfInWindow(Item $item, Warehouse $warehouse, InventoryLot $lot): void
    {
        $expiry = $lot->expiry_date?->toDateString();
        $status = self::alertStatus($expiry);
        if ($status === self::STATUS_OK) {
            return;
        }

        $lotId = (int) $lot->id;
        $warehouseId = (int) $warehouse->id;
        $itemLabel = trim((string) ($item->item_code ?: $item->name));
        $lotNumber = (string) $lot->lot_number;
        $warehouseName = (string) $warehouse->name;
        $isExpired = $status === self::STATUS_EXPIRED;

        $dispatch = function () use (
            $item,
            $warehouse,
            $lotId,
            $warehouseId,
            $itemLabel,
            $lotNumber,
            $warehouseName,
            $expiry,
            $isExpired,
        ): void {
            $fallbackHours = max(
                1,
                (int) config('notifications.lot_expiry.instant_suppression_fallback_hours', 48)
            );
            $cacheKey = $this->suppressionKey($lotId, $warehouseId);
            if (! Cache::add($cacheKey, true, now()->addHours($fallbackHours))) {
                return;
            }

            $this->dispatcher->dispatch(
                'stock.lot_expiry_item',
                [
                    'severity' => $isExpired ? 'critical' : 'warning',
                    'params' => [
                        'item_code' => $itemLabel,
                        'item_name' => (string) $item->name,
                        'lot_number' => $lotNumber,
                        'expiry_date' => (string) $expiry,
                        'warehouse_name' => $warehouseName,
                        'status' => $isExpired ? self::STATUS_EXPIRED : self::STATUS_EXPIRING,
                    ],
                    'mail_lines' => $isExpired
                        ? ['Lot :lot_number of :item_code expired on :expiry_date in :warehouse_name.']
                        : ['Lot :lot_number of :item_code expires on :expiry_date in :warehouse_name.'],
                    'action_path' => '/main/stock/lots',
                    'resource_type' => 'inventory_lot',
                    'resource_id' => (string) $lotId,
                ],
                RecipientQuery::permission(
                    'stock',
                    'view',
                    $warehouse->branch_id !== null ? (int) $warehouse->branch_id : null,
                ),
            );
        };

        $this->afterCommit($dispatch);
    }

    private function forgetLot(InventoryLot $lot): void
    {
        $lotId = (int) $lot->id;
        $this->afterCommit(function () use ($lotId): void {
            $warehouseIds = StockBalance::query()
                ->where('lot_id', $lotId)
                ->pluck('warehouse_id');

            foreach ($warehouseIds as $warehouseId) {
                Cache::forget($this->suppressionKey($lotId, (int) $warehouseId));
            }
        });
    }

    private function suppressionKey(int $lotId, int $warehouseId): string
    {
        $prefix = (string) config(
            'notifications.lot_expiry.instant_cache_key_prefix',
            'notifications:instant_lot_expiry',
        );
        $tenantId = function_exists('tenant') ? (string) (tenant('id') ?? 'central') : 'central';

        return implode(':', [
            $prefix,
            $tenantId,
            (string) $lotId,
            (string) $warehouseId,
        ]);
    }

    private function afterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
