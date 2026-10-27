<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Accounting\Support\JournalMath;
use App\Support\ExchangeRateSnapshot;

/**
 * Document amounts converted to the primary currency at the document's own rate.
 */
final class PrimaryMoney
{
    public static function convert(mixed $amount, mixed $rate): string
    {
        $rateValue = bcadd(
            (string) ($rate !== null && $rate !== '' ? $rate : '1'),
            '0',
            ExchangeRateSnapshot::SCALE,
        );

        return JournalMath::round(ExchangeRateSnapshot::toPrimary(
            JournalMath::of($amount),
            $rateValue,
            JournalMath::WORK_SCALE,
        ));
    }
}
