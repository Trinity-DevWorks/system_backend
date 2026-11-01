<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\AccountType;
use App\Modules\Accounting\Enums\FiscalYearStatus;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportAmounts;
use App\Modules\Branch\Models\Branch;
use App\Modules\CompanySetting\Models\CompanySetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves a fiscal year's profit or loss into retained earnings (or accumulated
 * losses), dated on the year end. One posted entry per branch, so every branch's
 * income and expense accounts start the next year at zero in branch reports.
 */
class FiscalYearService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly AccountMappingService $mappings,
        private readonly JournalEntryService $journalEntries,
        private readonly FiscalCalendar $fiscal,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $stored = FiscalYear::query()->with('closingEntry')->orderBy('starts_on')->get();
        $next = $this->nextOpenYear();
        $latestClosedEnd = $this->latestClosedEnd($stored);

        $rows = [];
        $seen = [];
        foreach ($stored as $year) {
            $starts = $this->dateString($year->starts_on);
            $seen[$starts] = true;
            $rows[] = $this->present($year, $next['ends_on'], $latestClosedEnd);
        }
        if (! isset($seen[$next['starts_on']])) {
            $rows[] = $this->presentComputed($next, true);
        }

        usort($rows, fn (array $a, array $b): int => strcmp((string) $a['starts_on'], (string) $b['starts_on']));

        return $rows;
    }

    public function close(string $endsOn, ?string $userId): FiscalYear
    {
        return DB::transaction(function () use ($endsOn, $userId): FiscalYear {
            $settings = CompanySetting::query()->lockForUpdate()->first();
            $bounds = $this->fiscal->yearEndingOn($endsOn);
            $next = $this->nextOpenYear();
            if ($bounds === null || $bounds['ends_on'] !== $next['ends_on']) {
                abort(422, 'Close the oldest open year first.', [
                    'X-Error-Code' => 'YEAR_CLOSE_OUT_OF_ORDER',
                ]);
            }

            $closedThrough = $settings?->booksClosedThrough();
            if ($closedThrough === null || $endsOn > $closedThrough) {
                abort(422, 'Lock the books through the year end before closing the year.', [
                    'X-Error-Code' => 'YEAR_NOT_LOCKED',
                ]);
            }

            $linesByBranch = $this->closingLinesByBranch($bounds['starts_on'], $bounds['ends_on']);
            if ($linesByBranch === []) {
                abort(422, 'There is nothing to close for this year.', [
                    'X-Error-Code' => 'YEAR_CLOSE_EMPTY',
                ]);
            }

            /** @var FiscalYear $year */
            $year = FiscalYear::query()->where('ends_on', $endsOn)->lockForUpdate()->first()
                ?? FiscalYear::query()->create([
                    'starts_on' => $bounds['starts_on'],
                    'ends_on' => $bounds['ends_on'],
                    'status' => FiscalYearStatus::Open,
                ]);
            if ($year->status === FiscalYearStatus::Closed) {
                abort(422, 'Close the oldest open year first.', [
                    'X-Error-Code' => 'YEAR_CLOSE_OUT_OF_ORDER',
                ]);
            }

            $firstEntry = null;
            foreach ($linesByBranch as $branchKey => $lines) {
                $branchId = $branchKey === '' ? null : (int) $branchKey;
                $entry = $this->journalEntries->createPosted([
                    'entry_date' => $bounds['ends_on'],
                    'source_type' => JournalEntry::SOURCE_YEAR_END_CLOSE,
                    'source_id' => $branchId === null ? (string) $year->id : $year->id.':'.$branchId,
                    'source_event' => JournalEntry::EVENT_POST,
                    'branch_id' => $branchId,
                    'description' => 'Year-end close '.$bounds['starts_on'].' to '.$bounds['ends_on'],
                ], $lines, $userId, true);
                $firstEntry ??= $entry;
            }

            $year->update([
                'status' => FiscalYearStatus::Closed,
                // The default branch's entry; closedResult() and reverse() use every branch entry.
                'closing_entry_id' => $firstEntry?->id,
                'closed_by' => $userId,
                'closed_at' => now(),
            ]);

            return $year->refresh()->load('closingEntry');
        });
    }

    public function reverse(FiscalYear $year, ?string $userId): FiscalYear
    {
        return DB::transaction(function () use ($year, $userId): FiscalYear {
            CompanySetting::query()->lockForUpdate()->first();
            /** @var FiscalYear $year */
            $year = FiscalYear::query()->lockForUpdate()->findOrFail($year->id);
            $latest = FiscalYear::query()
                ->where('status', FiscalYearStatus::Closed)
                ->orderByDesc('ends_on')
                ->lockForUpdate()
                ->first();
            if ($year->status !== FiscalYearStatus::Closed || $latest === null || $latest->id !== $year->id) {
                abort(422, 'Only the latest closed year can be reversed.', [
                    'X-Error-Code' => 'YEAR_CLOSE_NOT_LATEST',
                ]);
            }

            $entries = $this->closingEntries($year)->lockForUpdate()->get();
            if ($entries->isEmpty()) {
                abort(422, 'Only the latest closed year can be reversed.', [
                    'X-Error-Code' => 'YEAR_CLOSE_NOT_LATEST',
                ]);
            }

            // Older reversals of this year give their source ids back, so the new ones fit the unique key.
            $this->yearEntries($year)
                ->where('source_event', JournalEntry::EVENT_REVERSE)
                ->get()
                ->each(fn (JournalEntry $existing) => $this->freeSourceId($existing));

            foreach ($entries as $entry) {
                $this->journalEntries->reverseEntry($entry, $userId);
                $this->freeSourceId($entry->refresh());
            }

            $year->update([
                'status' => FiscalYearStatus::Open,
                'closing_entry_id' => null,
                'closed_by' => null,
                'closed_at' => null,
            ]);

            return $year->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function present(FiscalYear $year, ?string $nextEndsOn = null, ?string $latestClosedEnd = null): array
    {
        $year->loadMissing('closingEntry');
        $starts = $this->dateString($year->starts_on);
        $ends = $this->dateString($year->ends_on);
        $nextEndsOn ??= $this->nextOpenYear()['ends_on'];
        $latestClosedEnd ??= $this->latestClosedEnd(FiscalYear::query()->where('status', FiscalYearStatus::Closed)->get());
        $closed = $year->status === FiscalYearStatus::Closed;

        return [
            'id' => (int) $year->id,
            'starts_on' => $starts,
            'ends_on' => $ends,
            'status' => $year->status->value,
            'result' => $closed ? $this->closedResult($year) : $this->profit($starts, $ends),
            'closing_entry_id' => $year->closing_entry_id !== null ? (string) $year->closing_entry_id : null,
            'closing_entry_number' => $year->closingEntry?->entry_number,
            'closed_at' => $year->closed_at?->toIso8601String(),
            'can_close' => ! $closed && $ends === $nextEndsOn,
            'can_reverse' => $closed && $ends === $latestClosedEnd,
        ];
    }

    /**
     * Oldest year that is not closed. After a close, that is the following year.
     * Before any close, it is the year of the earliest posted entry.
     *
     * @return array{starts_on: string, ends_on: string}
     */
    private function nextOpenYear(): array
    {
        $latest = FiscalYear::query()
            ->where('status', FiscalYearStatus::Closed)
            ->orderByDesc('ends_on')
            ->first();
        if ($latest !== null) {
            return $this->fiscal->yearBounds(Carbon::parse($this->dateString($latest->ends_on))->addDay()->toDateString());
        }

        $earliest = JournalEntry::query()
            ->where('status', '!=', JournalEntryStatus::Draft->value)
            ->min('entry_date');
        if ($earliest === null) {
            return $this->fiscal->yearBounds($this->fiscal->today());
        }

        $date = $earliest instanceof \DateTimeInterface
            ? $earliest->format('Y-m-d')
            : substr((string) $earliest, 0, 10);

        return $this->fiscal->yearBounds($date);
    }

    /**
     * Closing lines for each branch that has income or expense in the year, so each branch nets to zero.
     * Older entries without a branch get their own closing entry without a branch.
     * The default branch comes first, entries without a branch last.
     *
     * @return array<string, list<JournalLineData>> keyed by branch id ('' = no branch)
     */
    private function closingLinesByBranch(string $startsOn, string $endsOn): array
    {
        $defaultBranch = Branch::defaultId();
        $rows = $this->balances->linesQuery()
            ->where('je.entry_date', '>=', $startsOn)
            ->where('je.entry_date', '<=', $endsOn)
            ->groupBy('je.branch_id', 'l.account_id')
            ->select('je.branch_id', 'l.account_id', DB::raw('SUM(l.debit) AS debit'), DB::raw('SUM(l.credit) AS credit'))
            ->get();

        /** @var array<string, array<int, array{debit: string, credit: string}>> $totalsByBranch */
        $totalsByBranch = [];
        foreach ($rows as $row) {
            $key = $row->branch_id === null ? '' : (string) $row->branch_id;
            $accountId = (int) $row->account_id;
            $current = $totalsByBranch[$key][$accountId] ?? ['debit' => '0', 'credit' => '0'];
            $totalsByBranch[$key][$accountId] = [
                'debit' => JournalMath::add($current['debit'], (string) $row->debit),
                'credit' => JournalMath::add($current['credit'], (string) $row->credit),
            ];
        }

        uksort($totalsByBranch, function (string $a, string $b) use ($defaultBranch): int {
            $rank = fn (string $key): array => [$key === '' ? 2 : ($key === (string) $defaultBranch ? 0 : 1), (int) $key];

            return $rank($a) <=> $rank($b);
        });

        $out = [];
        foreach ($totalsByBranch as $key => $totals) {
            $lines = $this->closingLines($totals);
            if ($lines !== []) {
                $out[(string) $key] = $lines;
            }
        }

        return $out;
    }

    /**
     * @param  array<int, array{debit: string, credit: string}>  $totals  one branch's year totals by account
     * @return list<JournalLineData>
     */
    private function closingLines(array $totals): array
    {
        $accounts = Account::query()->where('is_group', false)->orderBy('code')->get();
        $lines = [];
        $debitTotal = '0';
        $creditTotal = '0';

        foreach ($accounts as $account) {
            if ($account->type !== AccountType::Revenue && $account->type !== AccountType::Expense) {
                continue;
            }
            if (! isset($totals[$account->id])) {
                continue;
            }
            $natural = ReportAmounts::natural($account->type, $totals[$account->id]);
            if (ReportAmounts::zero($natural)) {
                continue;
            }
            $side = $this->clearingSide($account->type, $natural);
            $lines[] = new JournalLineData((int) $account->id, $side['debit'], $side['credit'], description: 'Year-end close');
            $debitTotal = JournalMath::add($debitTotal, $side['debit']);
            $creditTotal = JournalMath::add($creditTotal, $side['credit']);
        }

        if ($lines === []) {
            return [];
        }

        $gap = JournalMath::store(JournalMath::sub($debitTotal, $creditTotal));
        if (JournalMath::isPositive($gap, 4)) {
            $equity = $this->mappings->accountFor(AccountRole::RetainedEarnings);
            $lines[] = new JournalLineData((int) $equity->id, '0', $gap, description: 'Year-end close');
        } elseif (JournalMath::isNegative($gap, 4)) {
            $equity = $this->mappings->accountFor(AccountRole::AccumulatedLosses);
            $lines[] = new JournalLineData((int) $equity->id, JournalMath::store(JournalMath::abs($gap)), '0', description: 'Year-end close');
        }

        return $lines;
    }

    /**
     * @return array{debit: string, credit: string}
     */
    private function clearingSide(AccountType $type, string $natural): array
    {
        $amount = JournalMath::store(JournalMath::abs($natural));
        $normalDebit = $type === AccountType::Expense;
        if (JournalMath::isPositive($natural, 4)) {
            return $normalDebit
                ? ['debit' => '0', 'credit' => $amount]
                : ['debit' => $amount, 'credit' => '0'];
        }

        return $normalDebit
            ? ['debit' => $amount, 'credit' => '0']
            : ['debit' => '0', 'credit' => $amount];
    }

    /**
     * Signed profit for the window, matching the balance sheet current-year row.
     */
    private function profit(string $from, string $to): string
    {
        $accounts = Account::query()->where('is_group', false)->get();
        $totals = $this->balances->totals($from, $to);
        $profit = '0';
        foreach ($accounts as $account) {
            if ($account->type === null || ! isset($totals[$account->id])) {
                continue;
            }
            $natural = ReportAmounts::natural($account->type, $totals[$account->id]);
            $profit = match ($account->type) {
                AccountType::Revenue => JournalMath::add($profit, $natural),
                AccountType::Expense => JournalMath::sub($profit, $natural),
                default => $profit,
            };
        }

        return JournalMath::store($profit);
    }

    private function closedResult(FiscalYear $year): string
    {
        $entries = $this->closingEntries($year)->with('lines')->get();
        if ($entries->isEmpty()) {
            return '0.0000';
        }
        $retained = $this->mappings->accountFor(AccountRole::RetainedEarnings);
        $losses = $this->mappings->accountFor(AccountRole::AccumulatedLosses);
        $result = '0';
        foreach ($entries as $entry) {
            foreach ($entry->lines as $line) {
                if ((int) $line->account_id === (int) $retained->id || (int) $line->account_id === (int) $losses->id) {
                    $result = JournalMath::add($result, JournalMath::sub((string) $line->credit, (string) $line->debit));
                }
            }
        }

        return JournalMath::store($result);
    }

    /**
     * Every journal entry of this year's close (source id "<year>" or "<year>:<branch>").
     *
     * @return Builder<JournalEntry>
     */
    private function yearEntries(FiscalYear $year): Builder
    {
        $id = (string) $year->id;

        return JournalEntry::query()
            ->where('source_type', JournalEntry::SOURCE_YEAR_END_CLOSE)
            ->where(fn (Builder $q) => $q->where('source_id', $id)->orWhere('source_id', 'like', $id.':%'));
    }

    /**
     * The posted closing entries (one per branch) of a closed year.
     *
     * @return Builder<JournalEntry>
     */
    private function closingEntries(FiscalYear $year): Builder
    {
        return $this->yearEntries($year)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->where('status', JournalEntryStatus::Posted)
            ->orderBy('entry_number');
    }

    /**
     * @param  array{starts_on: string, ends_on: string}  $bounds
     * @return array<string, mixed>
     */
    private function presentComputed(array $bounds, bool $canClose): array
    {
        return [
            'id' => null,
            'starts_on' => $bounds['starts_on'],
            'ends_on' => $bounds['ends_on'],
            'status' => FiscalYearStatus::Open->value,
            'result' => $this->profit($bounds['starts_on'], $bounds['ends_on']),
            'closing_entry_id' => null,
            'closing_entry_number' => null,
            'closed_at' => null,
            'can_close' => $canClose,
            'can_reverse' => false,
        ];
    }

    /**
     * @param  Collection<int, FiscalYear>  $years
     */
    private function latestClosedEnd(Collection $years): ?string
    {
        $latest = null;
        foreach ($years as $year) {
            if ($year->status !== FiscalYearStatus::Closed) {
                continue;
            }
            $end = $this->dateString($year->ends_on);
            if ($latest === null || $end > $latest) {
                $latest = $end;
            }
        }

        return $latest;
    }

    private function freeSourceId(JournalEntry $entry): void
    {
        if ((string) $entry->source_id === (string) $entry->id) {
            return;
        }
        $entry->update(['source_id' => $entry->id]);
    }

    private function dateString(mixed $date): string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }
}
