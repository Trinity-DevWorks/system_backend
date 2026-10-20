<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Enums\JournalEntryStatus;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportAmounts;
use Illuminate\Support\Facades\DB;

/**
 * Moves the VAT collected and VAT paid balances into VAT payable (or the
 * credit carried forward) so the payable account is what is owed to the state.
 */
class VatSettlementService
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
        $entries = JournalEntry::query()
            ->where('source_type', JournalEntry::SOURCE_VAT_SETTLEMENT)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->with('lines')
            ->orderByDesc('source_id')
            ->orderByDesc('entry_date')
            ->get();

        $latestId = $entries->first(fn (JournalEntry $entry): bool => $entry->status === JournalEntryStatus::Posted)?->id;
        $accounts = $this->accounts();

        return $entries->map(fn (JournalEntry $entry): array => $this->present($entry, $accounts, $entry->id === $latestId))->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $periodTo): array
    {
        $this->assertPeriod($periodTo);
        $built = $this->build($periodTo);

        return [
            'period_to' => $periodTo,
            'output' => $built['output'],
            'input' => $built['input'],
            'net' => $built['net'],
            'lines' => $built['preview'],
        ];
    }

    public function settle(string $periodTo, string $entryDate, ?string $userId): JournalEntry
    {
        return DB::transaction(function () use ($periodTo, $entryDate, $userId): JournalEntry {
            $accounts = $this->accounts();
            Account::query()->whereKey($accounts['output']->id)->lockForUpdate()->first();
            $this->assertPeriod($periodTo);
            $this->assertNoOverlap($periodTo);
            $built = $this->build($periodTo);

            return $this->journalEntries->createPosted([
                'entry_date' => $entryDate,
                'source_type' => JournalEntry::SOURCE_VAT_SETTLEMENT,
                'source_id' => $periodTo,
                'source_event' => JournalEntry::EVENT_POST,
                'branch_id' => null,
                'description' => 'VAT settlement up to '.$periodTo,
            ], $built['lines'], $userId);
        });
    }

    public function reverse(JournalEntry $entry, ?string $userId): JournalEntry
    {
        return DB::transaction(function () use ($entry, $userId): JournalEntry {
            if ($entry->source_type !== JournalEntry::SOURCE_VAT_SETTLEMENT || $entry->source_event !== JournalEntry::EVENT_POST) {
                abort(404, 'VAT settlement not found.');
            }
            $latest = JournalEntry::query()
                ->where('source_type', JournalEntry::SOURCE_VAT_SETTLEMENT)
                ->where('source_event', JournalEntry::EVENT_POST)
                ->where('status', JournalEntryStatus::Posted)
                ->orderByDesc('source_id')
                ->lockForUpdate()
                ->first();
            if ($latest === null || $latest->id !== $entry->id) {
                abort(422, 'Only the latest VAT settlement can be reversed.', ['X-Error-Code' => 'VAT_SETTLEMENT_NOT_LATEST']);
            }

            return $this->journalEntries->reverseEntry($entry, $userId);
        });
    }

    private function assertPeriod(string $periodTo): void
    {
        if ($periodTo > $this->fiscal->today()) {
            abort(422, 'The VAT period cannot end in the future.', ['X-Error-Code' => 'VAT_SETTLEMENT_FUTURE']);
        }
    }

    private function assertNoOverlap(string $periodTo): void
    {
        $lastOpen = JournalEntry::query()
            ->where('source_type', JournalEntry::SOURCE_VAT_SETTLEMENT)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->where('status', JournalEntryStatus::Posted)
            ->orderByDesc('source_id')
            ->value('source_id');
        $samePeriod = JournalEntry::query()
            ->where('source_type', JournalEntry::SOURCE_VAT_SETTLEMENT)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->where('source_id', $periodTo)
            ->exists();
        if ($samePeriod || ($lastOpen !== null && $periodTo <= (string) $lastOpen)) {
            abort(422, 'This date overlaps a VAT settlement that is still posted.', ['X-Error-Code' => 'VAT_SETTLEMENT_OVERLAP']);
        }
    }

    /**
     * @return array{output: string, input: string, net: string, lines: list<JournalLineData>, preview: list<array<string, mixed>>}
     */
    private function build(string $periodTo): array
    {
        $accounts = $this->accounts();
        $totals = $this->balances->totals(null, $periodTo);
        $output = ReportAmounts::natural($accounts['output']->type, $totals[$accounts['output']->id] ?? []);
        $input = ReportAmounts::natural($accounts['input']->type, $totals[$accounts['input']->id] ?? []);
        if (ReportAmounts::zero($output) && ReportAmounts::zero($input)) {
            abort(422, 'There is no VAT to settle up to this date.', ['X-Error-Code' => 'VAT_SETTLEMENT_EMPTY']);
        }

        $net = JournalMath::store(JournalMath::sub($output, $input));
        $draft = [
            $this->clear($accounts['output'], $output, true),
            $this->clear($accounts['input'], $input, false),
        ];
        if (JournalMath::isPositive($net, 4)) {
            $draft[] = ['account' => $accounts['payable'], 'debit' => '0', 'credit' => $net, 'role' => AccountRole::VatPayable];
        } elseif (JournalMath::isNegative($net, 4)) {
            $draft[] = ['account' => $accounts['carried'], 'debit' => JournalMath::store(JournalMath::abs($net)), 'credit' => '0', 'role' => AccountRole::VatCreditCarriedForward];
        }

        $lines = [];
        $preview = [];
        foreach ($draft as $row) {
            if (ReportAmounts::zero($row['debit']) && ReportAmounts::zero($row['credit'])) {
                continue;
            }
            $lines[] = new JournalLineData((int) $row['account']->id, (string) $row['debit'], (string) $row['credit']);
            $preview[] = [
                'role' => $row['role']->value,
                'account_id' => (int) $row['account']->id,
                'code' => (string) $row['account']->code,
                'name' => (string) $row['account']->name,
                'name_ar' => $row['account']->name_ar !== null && trim((string) $row['account']->name_ar) !== ''
                    ? (string) $row['account']->name_ar
                    : null,
                'debit' => JournalMath::store($row['debit']),
                'credit' => JournalMath::store($row['credit']),
            ];
        }

        return [
            'output' => JournalMath::store($output),
            'input' => JournalMath::store($input),
            'net' => $net,
            'lines' => $lines,
            'preview' => $preview,
        ];
    }

    /**
     * @return array{account: Account, debit: string, credit: string, role: AccountRole}
     */
    private function clear(Account $account, string $naturalBalance, bool $creditNormal): array
    {
        $positive = JournalMath::isPositive($naturalBalance, 4);
        $amount = JournalMath::store(JournalMath::abs($naturalBalance));
        $debit = '0';
        $credit = '0';
        if ($positive) {
            if ($creditNormal) {
                $debit = $amount;
            } else {
                $credit = $amount;
            }
        } elseif (JournalMath::isNegative($naturalBalance, 4)) {
            if ($creditNormal) {
                $credit = $amount;
            } else {
                $debit = $amount;
            }
        }

        return [
            'account' => $account,
            'debit' => $debit,
            'credit' => $credit,
            'role' => $creditNormal ? AccountRole::VatOutput : AccountRole::VatInput,
        ];
    }

    /**
     * @param  array{output: Account, input: Account, payable: Account, carried: Account}  $accounts
     * @return array<string, mixed>
     */
    private function present(JournalEntry $entry, array $accounts, bool $canReverse): array
    {
        $output = '0';
        $input = '0';
        foreach ($entry->lines as $line) {
            if ((int) $line->account_id === (int) $accounts['output']->id) {
                $output = JournalMath::sub($line->debit, $line->credit);
            }
            if ((int) $line->account_id === (int) $accounts['input']->id) {
                $input = JournalMath::sub($line->credit, $line->debit);
            }
        }

        return [
            'id' => (string) $entry->id,
            'entry_number' => (string) $entry->entry_number,
            'entry_date' => $entry->entry_date?->toDateString(),
            'period_to' => (string) $entry->source_id,
            'output' => JournalMath::store($output),
            'input' => JournalMath::store($input),
            'net' => JournalMath::store(JournalMath::sub($output, $input)),
            'status' => $entry->status->value,
            'can_reverse' => $canReverse,
        ];
    }

    /**
     * @return array{output: Account, input: Account, payable: Account, carried: Account}
     */
    private function accounts(): array
    {
        return [
            'output' => $this->mappings->accountFor(AccountRole::VatOutput),
            'input' => $this->mappings->accountFor(AccountRole::VatInput),
            'payable' => $this->mappings->accountFor(AccountRole::VatPayable),
            'carried' => $this->mappings->accountFor(AccountRole::VatCreditCarriedForward),
        ];
    }
}
