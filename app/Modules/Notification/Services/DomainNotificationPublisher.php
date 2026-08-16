<?php

declare(strict_types=1);

namespace App\Modules\Notification\Services;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Inventory\Stock\Models\StockTransfer;
use App\Modules\Inventory\Stock\Services\PurchasingAlertService;
use App\Modules\Notification\Support\RecipientQuery;
use App\Modules\Rbac\Models\Role;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Support\Facades\Cache;

/**
 * Domain-facing helpers that build payloads and dispatch Phase 1 business notifications.
 *
 * What: Translates PO / transfer / user / low-stock events into NotificationDispatcher calls.
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

    private function branchIdForWarehouse(int $warehouseId): ?int
    {
        $branchId = Warehouse::query()->whereKey($warehouseId)->value('branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }
}
