<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Models\User;
use App\Modules\Accounting\Enums\FiscalYearStatus;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\CompanySetting\Models\CompanySetting;
use App\Modules\CompanySetting\Services\CompanySettingService;
use App\Services\PermissionService;

/**
 * The books-closed date. Moving it forward needs edit; moving it backward
 * needs reopen and cannot uncover a year that is still closed.
 */
class AccountingPeriodService
{
    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly PermissionService $permissions,
        private readonly CompanySettingService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $settings = CompanySetting::current();

        return [
            'books_closed_through' => $settings->booksClosedThrough(),
            'fiscal_year_start_month' => $this->fiscal->startMonth(),
            'fiscal_year_start_locked' => FiscalYear::query()->where('status', FiscalYearStatus::Closed)->exists(),
            'open_year' => $this->fiscal->yearBounds($this->fiscal->today()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateClosedThrough(?string $date, User $user): array
    {
        $settings = CompanySetting::singleton();
        $current = $settings->booksClosedThrough();
        $backward = $this->movesBackward($current, $date);
        $action = $backward ? 'reopen' : 'edit';
        if (! $this->permissions->userHas('accounting_periods', $action, $user)) {
            abort(403, 'Forbidden.');
        }

        if ($backward) {
            $latestClosedEnd = FiscalYear::query()
                ->where('status', FiscalYearStatus::Closed)
                ->orderByDesc('ends_on')
                ->value('ends_on');
            if ($latestClosedEnd !== null) {
                $end = $latestClosedEnd instanceof \DateTimeInterface
                    ? $latestClosedEnd->format('Y-m-d')
                    : substr((string) $latestClosedEnd, 0, 10);
                if ($date === null || $date < $end) {
                    abort(422, 'Reverse the closed year before moving the lock earlier than '.$end.'.', [
                        'X-Error-Code' => 'PERIOD_REOPEN_CLOSED_YEAR',
                    ]);
                }
            }
        }

        $settings->update(['books_closed_through' => $date]);
        $this->settings->forgetCache();

        return $this->show();
    }

    private function movesBackward(?string $current, ?string $next): bool
    {
        if ($next === $current) {
            return false;
        }
        if ($current === null) {
            return false;
        }

        return $next === null || $next < $current;
    }
}
