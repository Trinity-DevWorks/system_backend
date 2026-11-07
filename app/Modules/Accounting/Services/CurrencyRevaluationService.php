<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Branch\Models\Branch;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Month-end currency revaluation: re-prices foreign-currency balances on cash/bank, customer and
 * supplier accounts at the rate of a date and books the USD gap as an unrealized exchange gain or loss.
 * The entry is reversed the next day, so the real (realized) result is booked when money moves.
 * Only primary-currency values change; the foreign amounts stay as they are.
 */
class CurrencyRevaluationService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly AccountMappingService $mappings,
        private readonly JournalEntryService $journalEntries,
        private readonly ExchangeRateService $exchangeRates,
        private readonly FiscalCalendar $fiscal,
    ) {}

    /**
     * Past revaluations, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $entries = JournalEntry::query()
            ->where('source_type', JournalEntry::SOURCE_CURRENCY_REVALUATION)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->with(['lines', 'reversal'])
            ->orderByDesc('source_id')
            ->get();
        $latest = $entries->first()?->source_id;
        $accounts = $this->accounts();

        return $entries->map(function (JournalEntry $entry) use ($latest, $accounts): array {
            $gain = '0';
            $loss = '0';
            foreach ($entry->lines as $line) {
                if ((int) $line->account_id === (int) $accounts['gain']->id) {
                    $gain = JournalMath::add($gain, JournalMath::sub($line->credit, $line->debit));
                }
                if ((int) $line->account_id === (int) $accounts['loss']->id) {
                    $loss = JournalMath::add($loss, JournalMath::sub($line->debit, $line->credit));
                }
            }

            return [
                'date' => (string) $entry->source_id,
                'entry_id' => (string) $entry->id,
                'entry_number' => (string) $entry->entry_number,
                'reversal_date' => $entry->reversal?->entry_date?->toDateString(),
                'reversal_entry_number' => $entry->reversal?->entry_number,
                'gain' => JournalMath::store($gain),
                'loss' => JournalMath::store($loss),
                'can_undo' => $entry->source_id === $latest,
            ];
        })->all();
    }

    /**
     * @param  array<int|string, mixed>  $rates  currency id => rate (1 primary = rate × currency); missing = that date's rate
     * @return array<string, mixed>
     */
    public function preview(string $date, array $rates = []): array
    {
        $this->assertDate($date);
        $built = $this->build($date, $rates);

        return [
            'date' => $date,
            'rates' => $built['rates'],
            'lines' => $built['preview'],
            'gain' => $built['gain'],
            'loss' => $built['loss'],
        ];
    }

    /**
     * Posts the revaluation on `$date` and its reversal on the next day.
     *
     * @param  array<int|string, mixed>  $rates
     */
    public function post(string $date, array $rates, ?string $userId): JournalEntry
    {
        return DB::transaction(function () use ($date, $rates, $userId): JournalEntry {
            $accounts = $this->accounts();
            Account::query()->whereKey($accounts['gain']->id)->lockForUpdate()->first();
            $this->assertDate($date);
            $this->assertAfterLatest($date);
            $built = $this->build($date, $rates);
            if ($built['lines'] === []) {
                abort(422, 'Nothing changes in value at these rates.', ['X-Error-Code' => 'CURRENCY_REVALUATION_EMPTY']);
            }

            $entry = $this->journalEntries->createPosted([
                'entry_date' => $date,
                'source_type' => JournalEntry::SOURCE_CURRENCY_REVALUATION,
                'source_id' => $date,
                'source_event' => JournalEntry::EVENT_POST,
                // Revaluation covers the whole company: book it at head office.
                'branch_id' => Branch::defaultId(),
                'description' => 'Currency revaluation on '.$date,
            ], $built['lines'], $userId);

            $this->journalEntries->reverseEntry($entry, $userId, Carbon::parse($date)->addDay()->toDateString());

            return $entry->fresh(['reversal']) ?? $entry;
        });
    }

    /**
     * Removes the latest revaluation and its reversal (both periods must be open).
     */
    public function undo(string $date): void
    {
        DB::transaction(function () use ($date): void {
            $latest = JournalEntry::query()
                ->where('source_type', JournalEntry::SOURCE_CURRENCY_REVALUATION)
                ->where('source_event', JournalEntry::EVENT_POST)
                ->orderByDesc('source_id')
                ->lockForUpdate()
                ->value('source_id');
            if ($latest === null) {
                abort(404, 'Currency revaluation not found.');
            }
            if ((string) $latest !== $date) {
                abort(422, 'Only the latest currency revaluation can be undone.', ['X-Error-Code' => 'CURRENCY_REVALUATION_NOT_LATEST']);
            }
            $this->journalEntries->deleteAutomatic(JournalEntry::SOURCE_CURRENCY_REVALUATION, $date);
        });
    }

    private function assertDate(string $date): void
    {
        if ($date > $this->fiscal->today()) {
            abort(422, 'A currency revaluation cannot be dated in the future.', ['X-Error-Code' => 'CURRENCY_REVALUATION_FUTURE']);
        }
    }

    /**
     * Each revaluation is reversed the next day, so a new one must come after the last one.
     */
    private function assertAfterLatest(string $date): void
    {
        $latest = JournalEntry::query()
            ->where('source_type', JournalEntry::SOURCE_CURRENCY_REVALUATION)
            ->where('source_event', JournalEntry::EVENT_POST)
            ->orderByDesc('source_id')
            ->value('source_id');
        if ($latest !== null && $date <= (string) $latest) {
            abort(422, 'Choose a date after the last currency revaluation ('.$latest.').', ['X-Error-Code' => 'CURRENCY_REVALUATION_OVERLAP']);
        }
    }

    /**
     * @param  array<int|string, mixed>  $rates
     * @return array{lines: list<JournalLineData>, preview: list<array<string, mixed>>, rates: list<array<string, mixed>>, gain: string, loss: string}
     */
    private function build(string $date, array $rates): array
    {
        $primary = Currency::getPrimary();
        if ($primary === null) {
            abort(422, 'A primary currency is required.', ['X-Error-Code' => 'CURRENCY_REVALUATION_PRIMARY_REQUIRED']);
        }
        $accounts = $this->accounts();

        $rows = $this->balances->linesQuery()
            ->whereDate('je.entry_date', '<=', $date)
            ->where('l.currency_id', '!=', (int) $primary->id)
            ->whereIn('l.account_id', $this->monetaryAccountIds())
            ->groupBy('l.account_id', 'l.currency_id', 'l.party_type', 'l.party_id')
            ->selectRaw('l.account_id, l.currency_id, l.party_type, l.party_id, SUM(l.amount_currency) as foreign_balance, SUM(l.debit) - SUM(l.credit) as booked')
            ->get();

        $currencies = Currency::query()->whereIn('id', $rows->pluck('currency_id')->unique()->all())->get()->keyBy('id');
        $rateByCurrency = [];
        foreach ($currencies as $currency) {
            $given = $rates[(int) $currency->id] ?? $rates[(string) $currency->id] ?? null;
            $rateByCurrency[(int) $currency->id] = $given !== null && $given !== '' && JournalMath::isPositive($given, ExchangeRateSnapshot::SCALE)
                ? bcadd((string) $given, '0', ExchangeRateSnapshot::SCALE)
                : bcadd((string) $this->exchangeRates->getRateAsOfDate((string) $primary->code, (string) $currency->code, $date)['rate'], '0', ExchangeRateSnapshot::SCALE);
        }

        $accountRows = Account::query()->whereIn('id', $rows->pluck('account_id')->unique()->all())->get(['id', 'code', 'name', 'name_ar'])->keyBy('id');
        $partyNames = $this->partyNames($rows);

        $lines = [];
        $preview = [];
        $gain = '0';
        $loss = '0';
        foreach ($rows as $row) {
            $currencyId = (int) $row->currency_id;
            $rate = $rateByCurrency[$currencyId] ?? null;
            if ($rate === null || ! JournalMath::isPositive($rate, ExchangeRateSnapshot::SCALE)) {
                continue;
            }
            $booked = JournalMath::round($row->booked);
            $revalued = JournalMath::round(ExchangeRateSnapshot::toPrimary(JournalMath::of($row->foreign_balance), $rate, JournalMath::WORK_SCALE));
            $difference = JournalMath::round(JournalMath::sub($revalued, $booked));
            if (JournalMath::isZero($difference)) {
                continue;
            }

            $positive = JournalMath::isPositive($difference);
            $amount = JournalMath::store(JournalMath::abs($difference));
            $partyType = $row->party_type !== null ? (string) $row->party_type : null;
            $partyId = $row->party_id !== null ? (string) $row->party_id : null;
            $lines[] = new JournalLineData(
                accountId: (int) $row->account_id,
                debit: $positive ? $amount : '0',
                credit: $positive ? '0' : $amount,
                currencyId: $currencyId,
                exchangeRate: $rate,
                amountCurrency: '0',
                partyType: $partyType,
                partyId: $partyId,
                description: 'Revaluation at '.$rate,
            );
            if ($positive) {
                $gain = JournalMath::add($gain, $amount);
            } else {
                $loss = JournalMath::add($loss, $amount);
            }

            $account = $accountRows->get((int) $row->account_id);
            $preview[] = [
                'account_id' => (int) $row->account_id,
                'account_code' => $account !== null ? (string) $account->code : null,
                'account_name' => $account !== null ? (string) $account->name : null,
                'account_name_ar' => $account !== null && $account->name_ar !== null && trim((string) $account->name_ar) !== '' ? (string) $account->name_ar : null,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'party_name' => $partyType !== null && $partyId !== null ? ($partyNames[$partyType][$partyId] ?? null) : null,
                'currency_id' => $currencyId,
                'currency_code' => (string) ($currencies->get($currencyId)?->code ?? ''),
                'rate' => $rate,
                'foreign_balance' => JournalMath::store($row->foreign_balance),
                'booked' => JournalMath::store($booked),
                'revalued' => JournalMath::store($revalued),
                'difference' => JournalMath::store($difference),
            ];
        }

        if (JournalMath::isPositive($gain)) {
            $lines[] = new JournalLineData((int) $accounts['gain']->id, '0', JournalMath::store($gain), description: 'Unrealized exchange gain');
        }
        if (JournalMath::isPositive($loss)) {
            $lines[] = new JournalLineData((int) $accounts['loss']->id, JournalMath::store($loss), '0', description: 'Unrealized exchange loss');
        }

        $rateList = [];
        foreach ($currencies as $currency) {
            $rateList[] = ['currency_id' => (int) $currency->id, 'code' => (string) $currency->code, 'rate' => $rateByCurrency[(int) $currency->id] ?? null];
        }

        return [
            'lines' => $lines,
            'preview' => $preview,
            'rates' => $rateList,
            'gain' => JournalMath::store($gain),
            'loss' => JournalMath::store($loss),
        ];
    }

    /**
     * Ledger accounts that hold money or money owed: cash/bank, customers, suppliers, cheques.
     *
     * @return list<int>
     */
    private function monetaryAccountIds(): array
    {
        $ids = DB::table('cash_bank_accounts')->whereNotNull('account_id')->pluck('account_id')->map(fn ($id): int => (int) $id)->all();
        foreach ([AccountRole::Receivable, AccountRole::Payable, AccountRole::ChequesToCollect] as $role) {
            $ids[] = (int) $this->mappings->accountFor($role)->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<string, array<string, string>>
     */
    private function partyNames(iterable $rows): array
    {
        $ids = ['customer' => [], 'supplier' => []];
        foreach ($rows as $row) {
            if ($row->party_type === JournalEntryLine::PARTY_CUSTOMER || $row->party_type === JournalEntryLine::PARTY_SUPPLIER) {
                $ids[$row->party_type][] = (string) $row->party_id;
            }
        }

        return [
            'customer' => $ids['customer'] === [] ? [] : DB::table('customers')->whereIn('id', array_unique($ids['customer']))->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all(),
            'supplier' => $ids['supplier'] === [] ? [] : DB::table('suppliers')->whereIn('id', array_unique($ids['supplier']))->pluck('name', 'id')->map(fn ($n): string => (string) $n)->all(),
        ];
    }

    /**
     * @return array{gain: Account, loss: Account}
     */
    private function accounts(): array
    {
        return [
            'gain' => $this->mappings->accountFor(AccountRole::UnrealizedExchangeGain),
            'loss' => $this->mappings->accountFor(AccountRole::UnrealizedExchangeLoss),
        ];
    }
}
