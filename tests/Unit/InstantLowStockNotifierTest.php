<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Inventory\Stock\Enums\ReplenishmentAlertStatus;
use App\Modules\Notification\Services\InstantLowStockNotifier;
use App\Modules\Notification\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstantLowStockNotifierTest extends TestCase
{
    /**
     * @return array<string, array{ReplenishmentAlertStatus, ReplenishmentAlertStatus, bool}>
     */
    public static function transitionProvider(): array
    {
        return [
            'ok to below reorder' => [
                ReplenishmentAlertStatus::Ok,
                ReplenishmentAlertStatus::BelowReorder,
                true,
            ],
            'below reorder to below safety' => [
                ReplenishmentAlertStatus::BelowReorder,
                ReplenishmentAlertStatus::BelowSafety,
                true,
            ],
            'below safety to out of stock' => [
                ReplenishmentAlertStatus::BelowSafety,
                ReplenishmentAlertStatus::OutOfStock,
                true,
            ],
            'same alert remains quiet' => [
                ReplenishmentAlertStatus::BelowSafety,
                ReplenishmentAlertStatus::BelowSafety,
                false,
            ],
            'recovery remains quiet' => [
                ReplenishmentAlertStatus::BelowSafety,
                ReplenishmentAlertStatus::Ok,
                false,
            ],
            'partial recovery remains quiet' => [
                ReplenishmentAlertStatus::OutOfStock,
                ReplenishmentAlertStatus::BelowSafety,
                false,
            ],
        ];
    }

    #[DataProvider('transitionProvider')]
    public function test_only_worsening_alert_transitions_notify(
        ReplenishmentAlertStatus $previous,
        ReplenishmentAlertStatus $current,
        bool $expected,
    ): void {
        $this->assertSame(
            $expected,
            InstantLowStockNotifier::isWorseningAlertTransition($previous, $current),
        );
    }

    public function test_digest_releases_suppression_for_included_item_warehouse(): void
    {
        config()->set('notifications.low_stock.instant_cache_key_prefix', 'notifications:instant_low_stock');
        $key = 'notifications:instant_low_stock:central:item-a:7';
        Cache::put($key, true, now()->addHour());

        $notifier = new InstantLowStockNotifier(
            $this->createMock(NotificationDispatcher::class),
        );
        $notifier->releaseAfterDigest([
            ['item_id' => 'item-a', 'warehouse_id' => 7],
        ]);

        $this->assertFalse(Cache::has($key));
    }
}
