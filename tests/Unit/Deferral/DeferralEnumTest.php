<?php

declare(strict_types=1);

namespace Tests\Unit\Deferral;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Deferral\Enums\DeferralKind;
use App\Modules\RecurringEntry\Enums\RecurrenceFrequency;
use PHPUnit\Framework\TestCase;

class DeferralEnumTest extends TestCase
{
    public function test_kind_maps_to_account_types_and_roles(): void
    {
        $this->assertSame(AccountRole::PrepaidExpenses, DeferralKind::PrepaidExpense->deferralRole());
        $this->assertSame(AccountType::Asset, DeferralKind::PrepaidExpense->deferralAccountType());
        $this->assertSame(AccountType::Expense, DeferralKind::PrepaidExpense->recognitionAccountType());
        $this->assertSame(AccountType::Liability, DeferralKind::DeferredRevenue->deferralAccountType());
        $this->assertSame(AccountType::Revenue, DeferralKind::DeferredRevenue->recognitionAccountType());
    }

    public function test_frequency_month_steps(): void
    {
        $this->assertSame(1, RecurrenceFrequency::Monthly->monthsPerStep());
        $this->assertSame(3, RecurrenceFrequency::Quarterly->monthsPerStep());
        $this->assertSame(6, RecurrenceFrequency::SemiAnnual->monthsPerStep());
        $this->assertSame(12, RecurrenceFrequency::Yearly->monthsPerStep());
        $this->assertNull(RecurrenceFrequency::Daily->monthsPerStep());
        $this->assertNull(RecurrenceFrequency::Weekly->monthsPerStep());
    }
}
