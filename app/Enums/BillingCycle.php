<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * End date of one billing period starting on `$start`.
     */
    public function periodEnd(Carbon $start): Carbon
    {
        return match ($this) {
            self::Monthly => $start->copy()->addMonthNoOverflow(),
            self::Yearly => $start->copy()->addYearNoOverflow(),
        };
    }
}
