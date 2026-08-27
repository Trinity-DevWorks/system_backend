<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Notification\Services\InstantLotExpiryNotifier;
use App\Modules\Notification\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstantLotExpiryNotifierTest extends TestCase
{
    /**
     * @return array<string, array{?string, string, int, string}>
     */
    public static function statusProvider(): array
    {
        return [
            'no expiry is ok' => [null, '2026-08-27', 7, InstantLotExpiryNotifier::STATUS_OK],
            'expired yesterday' => ['2026-08-26', '2026-08-27', 7, InstantLotExpiryNotifier::STATUS_EXPIRED],
            'expires today' => ['2026-08-27', '2026-08-27', 7, InstantLotExpiryNotifier::STATUS_EXPIRING],
            'expires in 7 days' => ['2026-09-03', '2026-08-27', 7, InstantLotExpiryNotifier::STATUS_EXPIRING],
            'expires in 8 days is ok' => ['2026-09-04', '2026-08-27', 7, InstantLotExpiryNotifier::STATUS_OK],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_alert_status_window(
        ?string $expiryDate,
        string $today,
        int $withinDays,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            InstantLotExpiryNotifier::alertStatus($expiryDate, $today, $withinDays),
        );
    }

    public function test_digest_releases_suppression_for_included_lot_warehouse(): void
    {
        config()->set('notifications.lot_expiry.instant_cache_key_prefix', 'notifications:instant_lot_expiry');
        $key = 'notifications:instant_lot_expiry:central:9:7';
        Cache::put($key, true, now()->addHour());

        $notifier = new InstantLotExpiryNotifier(
            $this->createMock(NotificationDispatcher::class),
        );
        $notifier->releaseAfterDigest([
            ['lot_id' => 9, 'warehouse_id' => 7],
        ]);

        $this->assertFalse(Cache::has($key));
    }
}
