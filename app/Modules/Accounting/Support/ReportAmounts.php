<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Enums\AccountType;

/**
 * Reports show the natural sign of an account: credit − debit for revenue,
 * liabilities and equity; debit − credit for expenses and assets. A contra
 * account then comes out negative inside its section without a special case.
 */
final class ReportAmounts
{
    /**
     * @param  array{debit?: string, credit?: string}  $sum
     */
    public static function natural(?AccountType $type, array $sum): string
    {
        $debit = $sum['debit'] ?? '0';
        $credit = $sum['credit'] ?? '0';
        $net = match ($type) {
            AccountType::Revenue, AccountType::Liability, AccountType::Equity => JournalMath::sub($credit, $debit),
            default => JournalMath::sub($debit, $credit),
        };

        return JournalMath::store($net);
    }

    public static function zero(?string $amount): bool
    {
        return $amount === null || JournalMath::isZero($amount, 4);
    }

    public static function change(string $amount, ?string $compare): ?string
    {
        if ($compare === null) {
            return null;
        }

        return JournalMath::store(JournalMath::sub($amount, $compare));
    }
}
