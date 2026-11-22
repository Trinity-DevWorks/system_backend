<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Enums;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;

enum DeferralKind: string
{
    case PrepaidExpense = 'prepaid_expense';
    case DeferredRevenue = 'deferred_revenue';

    public function deferralRole(): AccountRole
    {
        return match ($this) {
            self::PrepaidExpense => AccountRole::PrepaidExpenses,
            self::DeferredRevenue => AccountRole::DeferredRevenue,
        };
    }

    public function deferralAccountType(): AccountType
    {
        return match ($this) {
            self::PrepaidExpense => AccountType::Asset,
            self::DeferredRevenue => AccountType::Liability,
        };
    }

    public function recognitionAccountType(): AccountType
    {
        return match ($this) {
            self::PrepaidExpense => AccountType::Expense,
            self::DeferredRevenue => AccountType::Revenue,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
