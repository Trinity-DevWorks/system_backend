<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Accounting\FixedAsset\Models\DepreciationRun;
use App\Modules\Branch\Models\Branch;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Services\InventoryLotService;
use App\Modules\Inventory\Stock\Services\PurchasingAlertService;
use App\Modules\Notification\Support\RecipientQuery;
use App\Modules\Rbac\Models\Role;
use App\Modules\RecurringEntry\Models\RecurringRun;
use App\Modules\RecurringEntry\Models\RecurringTemplate;
use App\Modules\Warehouse\Models\Warehouse;
use App\Support\TenantClock;
use Illuminate\Support\Facades\Cache;

/**
 * Domain-facing helpers that build payloads and dispatch Phase 1 business notifications.
 *
 * What: Translates PO / GRN / transfer / user / low-stock / lot-expiry events into NotificationDispatcher calls.
 * Used for: Hooks at the end of domain service methods (and the daily low-stock digest command).
 * Solves: Keeps payload shape, recipients, and action paths consistent without cluttering domain services.
 */
class DomainNotificationPublisher
{
    public function __construct(
        private readonly NotificationDispatcher $dispatcher,
        private readonly RecipientResolver $recipientResolver,
        private readonly PurchasingAlertService $purchasingAlerts,
        private readonly InstantLowStockNotifier $instantLowStockNotifier,
        private readonly InstantLotExpiryNotifier $instantLotExpiryNotifier,
        private readonly InventoryLotService $inventoryLots,
    ) {}

    public function userCreated(User $user): void
    {
        $this->dispatcher->dispatch(
            'user.created',
            [
                'params' => [
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'mail_lines' => [
                    'Hello :name,',
                    'An account was created for you on this workspace (:email).',
                    'Sign in with the credentials provided by your administrator.',
                ],
                'action_path' => '/main/profile',
                'resource_type' => 'user',
                'resource_id' => (string) $user->id,
            ],
            RecipientQuery::users([(string) $user->id]),
        );
    }

    public function userDeactivated(User $user): void
    {
        $this->dispatcher->dispatch(
            'user.deactivated',
            [
                'params' => [
                    'name' => (string) $user->name,
                ],
                'mail_lines' => [
                    'Hello :name,',
                    'Your account has been deactivated. Contact an administrator if this is unexpected.',
                ],
                'action_path' => '/main/profile',
                'resource_type' => 'user',
                'resource_id' => (string) $user->id,
            ],
            // Allow inactive notifiable for this one event so email can still go out.
            new RecipientQuery(userIds: [(string) $user->id], activeOnly: false),
        );
    }

    /**
     * @param  list<array{branch_id: int, role_id: int}>  $assignments
     */
    public function userRoleAssigned(User $user, array $assignments): void
    {
        $roleIds = collect($assignments)->pluck('role_id')->unique()->filter()->all();
        $roles = Role::query()->whereIn('id', $roleIds)->pluck('name', 'id');
        $branchIds = collect($assignments)->pluck('branch_id')->unique()->filter()->all();
        $branches = Branch::query()->whereIn('id', $branchIds)->pluck('name', 'id');

        $summary = collect($assignments)
            ->map(function (array $row) use ($roles, $branches): string {
                $roleName = $roles[(int) $row['role_id']] ?? ('#'.$row['role_id']);
                $branchName = $branches[(int) $row['branch_id']] ?? ('#'.$row['branch_id']);

                return "{$roleName} @ {$branchName}";
            })
            ->implode(', ');

        $this->dispatcher->dispatch(
            'user.role_assigned',
            [
                'params' => [
                    'name' => (string) $user->name,
                    'assignments' => $summary,
                ],
                'mail_lines' => [
                    'Hello :name,',
                    'Your branch role assignments were updated: :assignments.',
                ],
                'action_path' => '/main/profile',
                'resource_type' => 'user',
                'resource_id' => (string) $user->id,
            ],
            RecipientQuery::users([(string) $user->id]),
        );
    }

    public function branchUserAssigned(User $user, Branch $branch, ?Role $role = null): void
    {
        $this->dispatcher->dispatch(
            'branch.user_assigned',
            [
                'params' => [
                    'name' => (string) $user->name,
                    'branch_name' => (string) $branch->name,
                    'role_name' => $role?->name ?? '',
                ],
                'mail_lines' => [
                    'Hello :name,',
                    'You were assigned to branch :branch_name.',
                ],
                'action_path' => '/main/profile',
                'resource_type' => 'branch',
                'resource_id' => (string) $branch->id,
            ],
            RecipientQuery::users([(string) $user->id]),
        );
    }

    public function purchaseOrderConfirmed(PurchaseOrder $order, ?string $actorId): void
    {
        $this->dispatchPurchaseOrderEvent('purchase_order.confirmed', $order, $actorId, [
            'Purchase order :po_number was confirmed.',
        ]);
    }

    public function purchaseOrderSent(PurchaseOrder $order, ?string $actorId): void
    {
        $this->dispatchPurchaseOrderEvent('purchase_order.sent', $order, $actorId, [
            'Purchase order :po_number was marked as sent.',
        ]);
    }

    public function purchaseOrderCancelled(PurchaseOrder $order, ?string $actorId): void
    {
        $this->dispatchPurchaseOrderEvent('purchase_order.cancelled', $order, $actorId, [
            'Purchase order :po_number was cancelled.',
        ]);
    }

    public function purchaseOrderClosed(PurchaseOrder $order, ?string $actorId): void
    {
        $this->dispatchPurchaseOrderEvent('purchase_order.closed', $order, $actorId, [
            'Purchase order :po_number was fully received and closed.',
        ]);
    }

    public function goodsReceiptPosted(GoodsReceipt $receipt, ?string $actorId): void
    {
        $receipt->loadMissing(['warehouse:id,name,branch_id', 'purchaseOrder:id,po_number,created_by,warehouse_id']);

        $branchId = $this->branchIdForWarehouse((int) $receipt->warehouse_id);
        $poNumber = $receipt->purchaseOrder?->po_number;
        $userIds = array_values(array_filter([
            $receipt->created_by ? (string) $receipt->created_by : null,
            $receipt->purchaseOrder?->created_by ? (string) $receipt->purchaseOrder->created_by : null,
        ]));

        $this->dispatcher->dispatch(
            'goods_receipt.posted',
            [
                'params' => [
                    'grn_number' => (string) $receipt->grn_number,
                    'po_number' => $poNumber ? (string) $poNumber : '',
                    'warehouse_name' => (string) ($receipt->warehouse?->name ?? ''),
                ],
                'mail_lines' => $poNumber
                    ? ['Goods receipt :grn_number was posted against purchase order :po_number.']
                    : ['Goods receipt :grn_number was posted at :warehouse_name.'],
                'action_path' => '/main/stock/goods-receipts?drawer='.rawurlencode((string) $receipt->id).'&mode=view',
                'resource_type' => GoodsReceipt::REFERENCE_TYPE,
                'resource_id' => (string) $receipt->id,
            ],
            RecipientQuery::usersAndPermission($userIds, 'stock', 'view', $branchId),
            $actorId,
        );
    }

    public function stockTransferDispatched(StockTransfer $transfer, ?string $actorId): void
    {
        $this->dispatchStockTransferEvent('stock_transfer.dispatched', $transfer, $actorId, [
            'Stock transfer :transfer_number was dispatched.',
        ]);
    }

    public function stockTransferReceived(StockTransfer $transfer, ?string $actorId): void
    {
        $this->dispatchStockTransferEvent('stock_transfer.received', $transfer, $actorId, [
            'Stock transfer :transfer_number was received.',
        ]);
    }

    public function stockTransferCancelled(StockTransfer $transfer, ?string $actorId): void
    {
        $this->dispatchStockTransferEvent('stock_transfer.cancelled', $transfer, $actorId, [
            'Stock transfer :transfer_number was cancelled.',
        ]);
    }

    /**
     * Build a digest of current purchasing alerts and notify eligible users (cooldown-aware).
     *
     * @return array{sent: bool, alert_count: int, reason?: string}
     */
    public function lowStockDigest(): array
    {
        $alerts = $this->purchasingAlerts->list(['only_alerts' => true]);
        $alertCount = count($alerts);

        if ($alertCount === 0) {
            return ['sent' => false, 'alert_count' => 0, 'reason' => 'no_alerts'];
        }

        $hours = max(1, (int) config('notifications.low_stock.cooldown_hours', 24));
        $cachePrefix = (string) config(
            'notifications.low_stock.cache_key_prefix',
            'notifications:low_stock_digest'
        );
        $tenantId = function_exists('tenant') ? (string) (tenant('id') ?? 'central') : 'central';
        $cacheKey = $cachePrefix.':'.$tenantId;
        if (Cache::has($cacheKey)) {
            return ['sent' => false, 'alert_count' => $alertCount, 'reason' => 'cooldown'];
        }

        $critical = collect($alerts)->filter(
            fn (array $row): bool => ($row['status'] ?? '') === 'out_of_stock'
        )->count();

        $focusAlert = collect($alerts)->first(
            fn (array $row): bool => ($row['status'] ?? '') === 'out_of_stock'
        ) ?? $alerts[0];

        $focusId = isset($focusAlert['replenishment_id'])
            ? (int) $focusAlert['replenishment_id']
            : 0;

        $actionPath = $focusId > 0
            ? '/main/stock/purchasing-alerts?drawer='.rawurlencode((string) $focusId).'&mode=view'
            : '/main/stock/purchasing-alerts';

        $this->dispatcher->dispatch(
            'purchasing.low_stock',
            [
                'severity' => $critical > 0 ? 'critical' : 'warning',
                'params' => [
                    'alert_count' => $alertCount,
                    'critical_count' => $critical,
                ],
                'mail_lines' => [
                    'There are :alert_count purchasing alert(s) that need attention (:critical_count out of stock).',
                    'Open Purchasing Alerts in the app to review and create purchase orders.',
                ],
                'action_path' => $actionPath,
                'resource_type' => 'purchasing_alert',
                'resource_id' => $focusId > 0 ? (string) $focusId : null,
            ],
            RecipientQuery::permission('stock', 'view'),
        );

        // The digest is now the follow-up for these rows. A later worsening
        // movement may create a fresh instant alert; recovery also releases it.
        $this->instantLowStockNotifier->releaseAfterDigest($alerts);
        Cache::put($cacheKey, true, now()->addHours($hours));

        return ['sent' => true, 'alert_count' => $alertCount];
    }

    /**
     * Daily digest of on-hand lots that are expired or expire within the configured window.
     *
     * @return array{sent: bool, lot_count: int, expired_count: int, reason?: string}
     */
    public function lotExpiryDigest(): array
    {
        $withinDays = max(1, (int) config('notifications.lot_expiry.within_days', 7));
        $until = now()->addDays($withinDays)->toDateString();
        $rows = $this->inventoryLots->listOnHandExpiringOnOrBefore($until);
        $lotCount = count($rows);
        $expiredCount = collect($rows)->where('is_expired', true)->count();

        if ($lotCount === 0) {
            return ['sent' => false, 'lot_count' => 0, 'expired_count' => 0, 'reason' => 'no_lots'];
        }

        $hours = max(1, (int) config('notifications.lot_expiry.cooldown_hours', 24));
        $cachePrefix = (string) config(
            'notifications.lot_expiry.cache_key_prefix',
            'notifications:lot_expiry_digest'
        );
        $tenantId = function_exists('tenant') ? (string) (tenant('id') ?? 'central') : 'central';
        $cacheKey = $cachePrefix.':'.$tenantId;
        if (Cache::has($cacheKey)) {
            return ['sent' => false, 'lot_count' => $lotCount, 'expired_count' => $expiredCount, 'reason' => 'cooldown'];
        }

        $this->dispatcher->dispatch(
            'stock.lot_expiry',
            [
                'severity' => $expiredCount > 0 ? 'critical' : 'warning',
                'params' => [
                    'lot_count' => $lotCount,
                    'expired_count' => $expiredCount,
                    'within_days' => $withinDays,
                ],
                'mail_lines' => [
                    'There are :lot_count lot(s) on hand that expire within :within_days day(s) (:expired_count already expired).',
                    'Open Lots in the app to review expiry dates.',
                ],
                'action_path' => '/main/stock/lots',
                'resource_type' => 'inventory_lot',
                'resource_id' => null,
            ],
            RecipientQuery::permission('stock', 'view'),
        );

        $this->instantLotExpiryNotifier->releaseAfterDigest($rows);
        Cache::put($cacheKey, true, now()->addHours($hours));

        return ['sent' => true, 'lot_count' => $lotCount, 'expired_count' => $expiredCount];
    }

    /**
     * @param  list<string>  $mailLines
     */
    private function dispatchPurchaseOrderEvent(
        string $type,
        PurchaseOrder $order,
        ?string $actorId,
        array $mailLines,
    ): void {
        $branchId = $this->branchIdForWarehouse((int) $order->warehouse_id);
        $userIds = array_values(array_filter([
            $order->created_by ? (string) $order->created_by : null,
        ]));

        $this->dispatcher->dispatch(
            $type,
            [
                'params' => [
                    'po_number' => (string) $order->po_number,
                ],
                'mail_lines' => $mailLines,
                'action_path' => '/main/stock/purchase-orders?drawer='.rawurlencode((string) $order->id).'&mode=view',
                'resource_type' => PurchaseOrder::REFERENCE_TYPE,
                'resource_id' => (string) $order->id,
            ],
            RecipientQuery::usersAndPermission($userIds, 'stock', 'view', $branchId),
            $actorId,
        );
    }

    /**
     * @param  list<string>  $mailLines
     */
    private function dispatchStockTransferEvent(
        string $type,
        StockTransfer $transfer,
        ?string $actorId,
        array $mailLines,
    ): void {
        $fromBranch = $this->branchIdForWarehouse((int) $transfer->from_warehouse_id);
        $toBranch = $this->branchIdForWarehouse((int) $transfer->to_warehouse_id);

        $userIds = collect([
            $transfer->created_by ? (string) $transfer->created_by : null,
        ])->filter()->values();

        foreach (array_unique(array_filter([$fromBranch, $toBranch])) as $branchId) {
            $userIds = $userIds->merge(
                $this->recipientResolver
                    ->resolve(RecipientQuery::permission('stock', 'view', (int) $branchId))
                    ->pluck('id')
                    ->map(fn ($id): string => (string) $id)
            );
        }

        $this->dispatcher->dispatch(
            $type,
            [
                'params' => [
                    'transfer_number' => (string) $transfer->transfer_number,
                ],
                'mail_lines' => $mailLines,
                'action_path' => '/main/stock/transfers?drawer='.rawurlencode((string) $transfer->id).'&mode=view',
                'resource_type' => StockTransfer::REFERENCE_TYPE,
                'resource_id' => (string) $transfer->id,
            ],
            RecipientQuery::users($userIds->unique()->values()->all()),
            $actorId,
        );
    }

    public function depreciationPosted(DepreciationRun $run): void
    {
        $month = $run->period_month->format('Y-m');
        $this->dispatcher->dispatch(
            'depreciation.posted',
            [
                'params' => [
                    'month' => $month,
                    'total' => (string) $run->total,
                ],
                'mail_lines' => [
                    'Depreciation for :month was posted.',
                    'Total: :total.',
                ],
                'action_path' => '/main/accounting/depreciation',
                'resource_type' => 'fixed_asset_depreciation',
                'resource_id' => (string) $run->id,
            ],
            RecipientQuery::permission('depreciation_runs', 'view'),
        );
    }

    public function recurringRunFailed(RecurringTemplate $template, RecurringRun $run): void
    {
        $this->dispatcher->dispatch(
            'accounting.recurring_failed',
            [
                'params' => [
                    'template_number' => (string) ($template->template_number ?? $template->name),
                    'error_code' => (string) ($run->error_code ?? ''),
                ],
                'mail_lines' => [
                    'Recurring template :template_number failed (:error_code).',
                ],
                'action_path' => '/main/accounting/recurring-entries?drawer='.rawurlencode((string) $template->id).'&mode=view',
                'resource_type' => RecurringTemplate::REFERENCE_TYPE,
                'resource_id' => (string) $template->id,
            ],
            RecipientQuery::permission('recurring_entries', 'view'),
        );
    }

    public function recurringDraftsReady(int $count): void
    {
        if ($count < 1) {
            return;
        }
        $key = 'notifications:recurring_drafts:'.(string) tenant('id').':'.TenantClock::today()->toDateString();
        if (! Cache::add($key, 1, now()->addDay())) {
            return;
        }
        $this->dispatcher->dispatch(
            'accounting.recurring_drafts_ready',
            [
                'params' => ['count' => (string) $count],
                'mail_lines' => [
                    ':count recurring document(s) are waiting for review.',
                ],
                'action_path' => '/main/accounting/recurring-entries',
                'resource_type' => 'recurring_template',
                'resource_id' => null,
            ],
            RecipientQuery::permission('recurring_entries', 'view'),
        );
    }

    public function deferralPostingFailed(DeferralSchedule $schedule, DeferralScheduleLine $line, string $code): void
    {
        $this->dispatcher->dispatch(
            'accounting.deferral_posting_failed',
            [
                'params' => [
                    'schedule_number' => (string) ($schedule->schedule_number ?? $schedule->name),
                    'error_code' => $code,
                ],
                'mail_lines' => [
                    'Deferral :schedule_number could not be recognised (:error_code).',
                ],
                'action_path' => '/main/accounting/deferral-schedules?drawer='.rawurlencode((string) $schedule->id).'&mode=view',
                'resource_type' => DeferralSchedule::REFERENCE_TYPE,
                'resource_id' => (string) $schedule->id,
            ],
            RecipientQuery::permission('deferral_schedules', 'view'),
        );
    }

    private function branchIdForWarehouse(int $warehouseId): ?int
    {
        $branchId = Warehouse::query()->whereKey($warehouseId)->value('branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }

    /**
     * A posted document took one or more expense accounts over the approved budget.
     *
     * @param  list<array<string, mixed>>  $warnings
     */
    public function budgetExceeded(string $documentLabel, array $warnings): void
    {
        if ($warnings === []) {
            return;
        }
        $first = $warnings[0];
        $accounts = implode(', ', array_map(
            fn (array $w): string => trim(((string) ($w['account_code'] ?? '')).' '.((string) ($w['account_name'] ?? ''))),
            $warnings,
        ));
        $this->dispatcher->dispatch(
            'accounting.budget_exceeded',
            [
                'params' => [
                    'document' => $documentLabel,
                    'accounts' => $accounts,
                    'count' => count($warnings),
                ],
                'mail_lines' => [
                    ':document went over budget on: :accounts.',
                ],
                'action_path' => '/main/accounting/budget-vs-actual?budget_id='.rawurlencode((string) ($first['budget_id'] ?? '')),
                'resource_type' => 'budget',
                'resource_id' => (string) ($first['budget_id'] ?? ''),
            ],
            RecipientQuery::permission('budgets', 'view'),
        );
    }
}
