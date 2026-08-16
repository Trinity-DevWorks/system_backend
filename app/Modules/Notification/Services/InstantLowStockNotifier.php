<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Modules\Inventory\Stock\Models\ItemWarehouseReplenishment;
use App\Modules\Inventory\Stock\Support\ReplenishmentAlertRules;
use App\Modules\Notification\Support\RecipientQuery;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Publishes one immediate alert when an item×warehouse balance crosses into a
 * worse replenishment state. Further instant alerts stay suppressed until the
 * daily digest covers the item or stock fully recovers.
 */
final class InstantLowStockNotifier
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
    ) {}

    public function afterBalanceChanged(
        Item $item,
        Warehouse $warehouse,
        string $previousQuantity,
        string $newQuantity,
    ): void {
        if (! $item->allow_purchase) {
            return;
        }

        $rule = ItemWarehouseReplenishment::query()
            ->where('item_id', $item->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('is_active', true)
            ->first();

        if (! $rule) {
            return;
        }

        $previousStatus = ReplenishmentAlertRules::status(
            (float) $previousQuantity,
            (float) $rule->reorder_point_qty,
            (float) $rule->safety_stock_qty,
        );
        $newStatus = ReplenishmentAlertRules::status(
            (float) $newQuantity,
            (float) $rule->reorder_point_qty,
            (float) $rule->safety_stock_qty,
        );

        if ($newStatus === ReplenishmentAlertStatus::Ok) {
            $this->afterCommit(
                fn (): bool => Cache::forget(
                    $this->suppressionKey((string) $item->id, (int) $warehouse->id)
                )
            );

            return;
        }

        if (! self::isWorseningAlertTransition($previousStatus, $newStatus)) {
            return;
        }

        $dispatch = function () use ($item, $warehouse, $rule, $newQuantity, $newStatus): void {
            $fallbackHours = max(
                1,
                (int) config('notifications.low_stock.instant_suppression_fallback_hours', 48)
            );
            $cacheKey = $this->suppressionKey(
                (string) $item->id,
                (int) $warehouse->id,
            );

            if (! Cache::add($cacheKey, true, now()->addHours($fallbackHours))) {
                return;
            }

            $itemLabel = trim((string) ($item->item_code ?: $item->sku ?: $item->name));
            $severity = $newStatus === ReplenishmentAlertStatus::OutOfStock
                ? 'critical'
                : 'warning';

            $this->dispatcher->dispatch(
                'purchasing.low_stock_item',
                [
                    'severity' => $severity,
                    'params' => [
                        'item_code' => $itemLabel,
                        'item_name' => (string) $item->name,
                        'warehouse_name' => (string) $warehouse->name,
                        'on_hand_qty' => ReplenishmentAlertRules::formatQty((float) $newQuantity),
                        'status' => $newStatus->value,
                    ],
                    'mail_lines' => [
                        ':item_code — :item_name is low in :warehouse_name.',
                        'Current on-hand quantity: :on_hand_qty.',
                    ],
                    'action_path' => '/main/stock/purchasing-alerts?drawer='
                        .rawurlencode((string) $rule->id).'&mode=view',
                    'resource_type' => 'purchasing_alert',
                    'resource_id' => (string) $rule->id,
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

    public static function isWorseningAlertTransition(
        ReplenishmentAlertStatus $previous,
        ReplenishmentAlertStatus $current,
    ): bool {
        return self::severityRank($current) > self::severityRank($previous);
    }

    private static function severityRank(ReplenishmentAlertStatus $status): int
    {
        return match ($status) {
            ReplenishmentAlertStatus::Ok => 0,
            ReplenishmentAlertStatus::BelowReorder => 1,
            ReplenishmentAlertStatus::BelowSafety => 2,
            ReplenishmentAlertStatus::OutOfStock => 3,
        };
    }

    /**
     * Release suppression for all item×warehouse rows included in a sent digest.
     *
     * @param  list<array<string, mixed>>  $alerts
     */
    public function releaseAfterDigest(array $alerts): void
    {
        foreach ($alerts as $alert) {
            $itemId = isset($alert['item_id']) ? (string) $alert['item_id'] : '';
            $warehouseId = isset($alert['warehouse_id']) ? (int) $alert['warehouse_id'] : 0;

            if ($itemId !== '' && $warehouseId > 0) {
                Cache::forget($this->suppressionKey($itemId, $warehouseId));
            }
        }
    }

    private function suppressionKey(string $itemId, int $warehouseId): string
    {
        $prefix = (string) config(
            'notifications.low_stock.instant_cache_key_prefix',
            'notifications:instant_low_stock',
        );
        $tenantId = function_exists('tenant') ? (string) (tenant('id') ?? 'central') : 'central';

        return implode(':', [
            $prefix,
            $tenantId,
            $itemId,
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
