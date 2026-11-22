<?php

declare(strict_types=1);

namespace App\Modules\RecurringEntry\Enums;

enum RecurrenceFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Yearly = 'yearly';

    /**
     * Months added per step for month-based frequencies. Null for daily and weekly.
     */
    public function monthsPerStep(): ?int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Yearly => 12,
            self::Daily, self::Weekly => null,
        };
    }

    public function isMonthlyType(): bool
    {
        return $this->monthsPerStep() !== null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
