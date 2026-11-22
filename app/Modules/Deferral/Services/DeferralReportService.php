<?php

declare(strict_types=1);

namespace App\Modules\Deferral\Services;

use App\Modules\Accounting\Enums\NormalBalance;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Deferral\Enums\DeferralLineStatus;
use App\Modules\Deferral\Enums\DeferralStatus;
use App\Modules\Deferral\Models\DeferralSchedule;
use App\Modules\Deferral\Models\DeferralScheduleLine;
use Illuminate\Support\Carbon;

class DeferralReportService
{
    public function __construct(private readonly AccountBalanceService $balances) {}

    /**
     * @return array{as_of: string, accounts: list<array<string, mixed>>}
     */
    public function balances(?string $asOf, ?string $kind, ?int $accountId): array
    {
        $asOf = $asOf !== null && $asOf !== '' ? substr($asOf, 0, 10) : Carbon::today()->toDateString();
        $query = DeferralSchedule::query()
            ->with(['deferralAccount:id,code,name,normal_balance', 'recognitionAccount:id,code,name', 'currency:id,code,symbol'])
            ->whereNotIn('status', [DeferralStatus::Draft->value, DeferralStatus::Cancelled->value]);
        if ($kind !== null && $kind !== '') {
            $query->where('kind', $kind);
        }
        if ($accountId !== null && $accountId > 0) {
            $query->where('deferral_account_id', $accountId);
        }
        $schedules = $query->orderBy('deferral_account_id')->orderBy('schedule_number')->get();
        $posted = DeferralScheduleLine::query()
            ->whereIn('deferral_schedule_id', $schedules->pluck('id'))
            ->where('status', DeferralLineStatus::Posted->value)
            ->whereDate('posting_date', '<=', $asOf)
            ->get(['deferral_schedule_id', 'amount', 'amount_primary'])
            ->groupBy('deferral_schedule_id');

        $gl = $this->balances->totals(null, $asOf);
        $grouped = [];
        foreach ($schedules as $schedule) {
            $recognized = '0';
            $recognizedPrimary = '0';
            foreach ($posted->get($schedule->id, collect()) as $line) {
                $recognized = JournalMath::add($recognized, $line->amount);
                $recognizedPrimary = JournalMath::add($recognizedPrimary, $line->amount_primary);
            }
            $remaining = JournalMath::store(JournalMath::sub((string) $schedule->total_amount, $recognized));
            $remainingPrimary = JournalMath::store(JournalMath::sub((string) $schedule->total_amount_primary, $recognizedPrimary));
            $accountIdKey = (int) $schedule->deferral_account_id;
            if (! isset($grouped[$accountIdKey])) {
                $account = $schedule->deferralAccount;
                $sum = $gl[$accountIdKey] ?? ['debit' => '0', 'credit' => '0'];
                $net = AccountBalanceService::net($sum);
                $balance = $account?->normal_balance === NormalBalance::Credit
                    ? JournalMath::store(JournalMath::neg($net))
                    : $net;
                $grouped[$accountIdKey] = [
                    'account' => [
                        'id' => $accountIdKey,
                        'code' => (string) ($account->code ?? ''),
                        'name' => (string) ($account->name ?? ''),
                    ],
                    'gl_balance' => $balance,
                    'schedules_remaining' => '0.0000',
                    'difference' => '0.0000',
                    'schedules' => [],
                ];
            }
            $grouped[$accountIdKey]['schedules_remaining'] = JournalMath::store(
                JournalMath::add($grouped[$accountIdKey]['schedules_remaining'], $remainingPrimary)
            );
            $grouped[$accountIdKey]['schedules'][] = [
                'id' => (string) $schedule->id,
                'schedule_number' => $schedule->schedule_number,
                'name' => $schedule->name,
                'kind' => $schedule->kind->value,
                'status' => $schedule->status->value,
                'currency_code' => $schedule->currency?->code,
                'total_amount' => (string) $schedule->total_amount,
                'recognized_amount' => JournalMath::store($recognized),
                'remaining_amount' => $remaining,
                'remaining_amount_primary' => $remainingPrimary,
            ];
        }

        $accounts = [];
        foreach ($grouped as $row) {
            $row['difference'] = JournalMath::store(JournalMath::sub($row['gl_balance'], $row['schedules_remaining']));
            $accounts[] = $row;
        }

        return ['as_of' => $asOf, 'accounts' => $accounts];
    }

    /**
     * @return array{from: string, to: string, months: list<string>, rows: list<array<string, mixed>>}
     */
    public function forecast(?string $from, ?string $to, ?string $kind): array
    {
        $from = $from !== null && $from !== '' ? substr($from, 0, 10) : Carbon::today()->startOfMonth()->toDateString();
        $to = $to !== null && $to !== '' ? substr($to, 0, 10) : Carbon::parse($from)->addMonths(11)->endOfMonth()->toDateString();
        $query = DeferralScheduleLine::query()
            ->with('schedule.recognitionAccount:id,code,name')
            ->where('status', DeferralLineStatus::Pending->value)
            ->whereDate('posting_date', '>=', $from)
            ->whereDate('posting_date', '<=', $to)
            ->whereHas('schedule', function ($q) use ($kind): void {
                $q->whereIn('status', [DeferralStatus::Active->value, DeferralStatus::Paused->value]);
                if ($kind !== null && $kind !== '') {
                    $q->where('kind', $kind);
                }
            });

        $months = [];
        $cursor = Carbon::parse($from)->startOfMonth();
        $end = Carbon::parse($to)->startOfMonth();
        while ($cursor->lessThanOrEqualTo($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor->addMonth();
        }

        $rows = [];
        foreach ($query->get() as $line) {
            $account = $line->schedule?->recognitionAccount;
            if (! $account instanceof Account) {
                continue;
            }
            $key = (int) $account->id;
            if (! isset($rows[$key])) {
                $amounts = [];
                foreach ($months as $month) {
                    $amounts[$month] = '0.0000';
                }
                $rows[$key] = [
                    'account' => ['id' => $key, 'code' => (string) $account->code, 'name' => (string) $account->name],
                    'amounts' => $amounts,
                    'total' => '0.0000',
                ];
            }
            $month = $line->posting_date->format('Y-m');
            if (! isset($rows[$key]['amounts'][$month])) {
                continue;
            }
            $rows[$key]['amounts'][$month] = JournalMath::store(JournalMath::add($rows[$key]['amounts'][$month], $line->amount_primary));
            $rows[$key]['total'] = JournalMath::store(JournalMath::add($rows[$key]['total'], $line->amount_primary));
        }

        return ['from' => $from, 'to' => $to, 'months' => $months, 'rows' => array_values($rows)];
    }
}
