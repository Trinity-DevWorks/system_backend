<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrder;
use App\Modules\Notification\Notifications\BusinessNotification;
use App\Modules\Notification\Services\DomainNotificationPublisher;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithTenant;
use Tests\TestCase;

/**
 * Tenant-level feature coverage for inbox ownership, hygiene, retention, and a
 * representative purchase-order notification.
 */
#[Group('notifications')]
class NotificationSystemTest extends TestCase
{
    use InteractsWithTenant;
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTenant('notification_api');
        $this->token = $this->tenantBearerToken();
        // Tenant bootstrap emits a legitimate branch-assignment notification;
        // each test starts from a controlled inbox fixture instead.
        $this->tenant->run(function (): void {
            $this->tenantUser->notifications()->delete();
        });
    }

    protected function tearDown(): void
    {
        $this->tearDownTenant();

        parent::tearDown();
    }

    public function test_inbox_lists_only_authenticated_users_unread_notifications(): void
    {
        $this->tenant->run(function (): void {
            $this->createNotification($this->tenantUser, 'purchase_order.confirmed');
            $this->createNotification($this->tenantUser, 'stock_transfer.received', now());

            $other = User::factory()->create(['is_active' => true]);
            $this->createNotification($other, 'purchase_order.cancelled');
        });

        $this->asTenantRequest($this->token)
            ->getJson($this->tenantUrl('/notifications?unread=1'))
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.type', 'purchase_order.confirmed')
            ->assertJsonPath('data.items.0.params.po_number', 'PO-TEST-1');
    }

    public function test_mark_read_is_scoped_to_authenticated_user(): void
    {
        [$ownId, $otherId] = $this->tenant->run(function (): array {
            $other = User::factory()->create(['is_active' => true]);

            return [
                $this->createNotification($this->tenantUser, 'purchase_order.confirmed'),
                $this->createNotification($other, 'purchase_order.cancelled'),
            ];
        });

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/notifications/{$ownId}/read"))
            ->assertOk()
            ->assertJsonPath('data.read', true)
            ->assertJsonPath('data.unread_count', 0);

        $this->asTenantRequest($this->token)
            ->postJson($this->tenantUrl("/notifications/{$otherId}/read"))
            ->assertNotFound()
            ->assertJsonPath('code', 'NOTIFICATION_NOT_FOUND');
    }

    public function test_clear_read_and_clear_all_only_delete_authenticated_users_rows(): void
    {
        $otherUserId = $this->tenant->run(function (): string {
            $this->createNotification($this->tenantUser, 'purchase_order.confirmed');
            $this->createNotification($this->tenantUser, 'stock_transfer.received', now());

            $other = User::factory()->create(['is_active' => true]);
            $this->createNotification($other, 'purchase_order.cancelled', now());

            return (string) $other->id;
        });

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl('/notifications/read'))
            ->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.unread_count', 1);

        $this->asTenantRequest($this->token)
            ->deleteJson($this->tenantUrl('/notifications'))
            ->assertOk()
            ->assertJsonPath('data.deleted', 1)
            ->assertJsonPath('data.unread_count', 0);

        $this->tenant->run(function () use ($otherUserId): void {
            $other = User::query()->findOrFail($otherUserId);
            $this->assertSame(1, $other->notifications()->count());
        });
    }

    public function test_prune_removes_only_expired_read_notifications(): void
    {
        $this->tenant->run(function (): void {
            $oldRead = $this->createNotification(
                $this->tenantUser,
                'purchase_order.confirmed',
                now()->subDays(120),
            );
            $this->setNotificationCreatedAt($oldRead, now()->subDays(120));

            $oldUnread = $this->createNotification($this->tenantUser, 'purchase_order.sent');
            $this->setNotificationCreatedAt($oldUnread, now()->subDays(120));

            $this->createNotification($this->tenantUser, 'stock_transfer.received', now());
        });

        $this->artisan('notifications:prune', ['--days' => 90])
            ->assertSuccessful();

        $this->tenant->run(function (): void {
            $this->assertSame(2, $this->tenantUser->notifications()->count());
            $this->assertSame(1, $this->tenantUser->unreadNotifications()->count());
        });
    }

    public function test_purchase_order_confirmation_dispatches_typed_notification_to_creator(): void
    {
        Notification::fake();

        $this->tenant->run(function (): void {
            $warehouse = Warehouse::query()->create([
                'name' => 'Notification Test Warehouse',
                'shortcut_name' => 'NTW',
                'type' => 'central',
                'is_active' => true,
            ]);

            $order = new PurchaseOrder([
                'po_number' => 'PO-NOTIFY-001',
                'warehouse_id' => $warehouse->id,
                'created_by' => (string) $this->tenantUser->id,
            ]);
            $order->setAttribute('id', (string) Str::uuid());

            app(DomainNotificationPublisher::class)->purchaseOrderConfirmed($order, null);

            Notification::assertSentTo(
                $this->tenantUser,
                BusinessNotification::class,
                fn (BusinessNotification $notification): bool => $notification->businessType === 'purchase_order.confirmed'
                    && ($notification->payload['params']['po_number'] ?? null) === 'PO-NOTIFY-001'
                    && str_contains(
                        (string) ($notification->payload['action_path'] ?? ''),
                        (string) $order->id,
                    ),
            );
        });
    }

    public function test_goods_receipt_posted_dispatches_typed_notification(): void
    {
        Notification::fake();

        $this->tenant->run(function (): void {
            $warehouse = Warehouse::query()->create([
                'name' => 'GRN Notify Warehouse',
                'shortcut_name' => 'GNW',
                'type' => 'central',
                'is_active' => true,
            ]);

            $receipt = new GoodsReceipt([
                'grn_number' => 'GRN-000042',
                'warehouse_id' => $warehouse->id,
                'created_by' => (string) $this->tenantUser->id,
            ]);
            $receipt->setAttribute('id', (string) Str::uuid());

            app(DomainNotificationPublisher::class)->goodsReceiptPosted($receipt, null);

            Notification::assertSentTo(
                $this->tenantUser,
                BusinessNotification::class,
                fn (BusinessNotification $notification): bool => $notification->businessType === 'goods_receipt.posted'
                    && ($notification->payload['params']['grn_number'] ?? null) === 'GRN-000042'
                    && str_contains(
                        (string) ($notification->payload['action_path'] ?? ''),
                        (string) $receipt->id,
                    ),
            );
        });
    }

    public function test_purchase_order_closed_dispatches_typed_notification(): void
    {
        Notification::fake();

        $this->tenant->run(function (): void {
            $warehouse = Warehouse::query()->create([
                'name' => 'PO Closed Warehouse',
                'shortcut_name' => 'PCW',
                'type' => 'central',
                'is_active' => true,
            ]);

            $order = new PurchaseOrder([
                'po_number' => 'PO-CLOSED-001',
                'warehouse_id' => $warehouse->id,
                'created_by' => (string) $this->tenantUser->id,
            ]);
            $order->setAttribute('id', (string) Str::uuid());

            app(DomainNotificationPublisher::class)->purchaseOrderClosed($order, null);

            Notification::assertSentTo(
                $this->tenantUser,
                BusinessNotification::class,
                fn (BusinessNotification $notification): bool => $notification->businessType === 'purchase_order.closed'
                    && ($notification->payload['params']['po_number'] ?? null) === 'PO-CLOSED-001'
                    && str_contains(
                        (string) ($notification->payload['action_path'] ?? ''),
                        (string) $order->id,
                    ),
            );
        });
    }

    public function test_lot_expiry_digest_skips_when_no_on_hand_lots_are_expiring(): void
    {
        $this->tenant->run(function (): void {
            $result = app(DomainNotificationPublisher::class)->lotExpiryDigest();

            $this->assertFalse($result['sent']);
            $this->assertSame('no_lots', $result['reason'] ?? null);
            $this->assertSame(0, $result['lot_count']);
        });
    }

    private function createNotification(
        User $user,
        string $type,
        mixed $readAt = null,
    ): string {
        $id = (string) Str::uuid();

        $user->notifications()->create([
            'id' => $id,
            'type' => BusinessNotification::class,
            'data' => [
                'type' => $type,
                'severity' => 'info',
                'params' => ['po_number' => 'PO-TEST-1'],
                'action_path' => '/main/stock/purchase-orders?drawer=test&mode=view',
            ],
            'read_at' => $readAt,
        ]);

        return $id;
    }

    private function setNotificationCreatedAt(string $notificationId, mixed $createdAt): void
    {
        $this->tenantUser->notifications()
            ->whereKey($notificationId)
            ->update(['created_at' => $createdAt]);
    }
}
