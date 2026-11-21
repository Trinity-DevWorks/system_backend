<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use App\Support\ExchangeRateSnapshot;

/**
 * Validates journal lines and turns them into rows ready for `journal_entry_lines`.
 */
class JournalLineRules
{
    public function __construct(
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * @param  list<JournalLineData>  $lines
     * @return list<array<string, mixed>>
     */
    public function prepare(array $lines, bool $manual): array
    {
        $accounts = Account::query()
            ->whereIn('id', array_unique(array_map(fn (JournalLineData $l): int => $l->accountId, $lines)))
            ->get()
            ->keyBy('id');
        $roles = $this->rolesByAccount();
        $primaryId = (int) (Currency::getPrimary()?->id ?? 0);

        $rows = [];
        foreach ($lines as $index => $line) {
            /** @var Account|null $account */
            $account = $accounts->get($line->accountId);
            if ($account === null || $account->is_group) {
                $this->fail('Journal lines can only post to posting accounts.', 'ACCOUNT_NOT_POSTABLE', $index);
            }
            if (! $account->is_active) {
                $this->fail("Account {$account->code} is inactive.", 'LEDGER_ACCOUNT_INACTIVE', $index);
            }

            $accountRoles = $roles[$account->id] ?? [];
            if ($manual) {
                foreach ($accountRoles as $role) {
                    if ($role->isControlled()) {
                        $this->fail("Account {$account->code} is controlled by documents and cannot take manual entries.", 'ACCOUNT_CONTROLLED', $index);
                    }
                }
            }

            $debit = JournalMath::round($line->debit);
            $credit = JournalMath::round($line->credit);
            if (JournalMath::isNegative($debit) || JournalMath::isNegative($credit)
                || JournalMath::isPositive($debit) === JournalMath::isPositive($credit)) {
                $this->fail('Each journal line needs an amount on exactly one side.', 'JOURNAL_LINE_INVALID', $index);
            }

            $currencyId = $line->currencyId ?? $account->currency_id ?? $primaryId;
            if ($account->currency_id !== null && $currencyId !== $account->currency_id) {
                $this->fail("Account {$account->code} only accepts its own currency.", 'ACCOUNT_CURRENCY_MISMATCH', $index);
            }
            $rate = $line->exchangeRate !== null
                ? bcadd($line->exchangeRate, '0', ExchangeRateSnapshot::SCALE)
                : ExchangeRateSnapshot::resolve($this->exchangeRateService, $currencyId, null, 'JOURNAL');

            $signed = JournalMath::sub($debit, $credit);
            $amountCurrency = $line->amountCurrency !== null
                ? JournalMath::store($line->amountCurrency)
                : JournalMath::store(JournalMath::round(JournalMath::mul($signed, $rate), JournalMath::STORAGE_SCALE));

            [$partyType, $partyId] = $this->party($line, $accountRoles, $manual, $index);

            $rows[] = [
                'account_id' => (int) $account->id,
                'debit' => JournalMath::store($debit),
                'credit' => JournalMath::store($credit),
                'currency_id' => $currencyId,
                'exchange_rate' => $rate,
                'amount_currency' => $amountCurrency,
                'party_type' => $partyType,
                'party_id' => $partyId,
                'description' => $line->description !== null ? mb_substr($line->description, 0, 500) : null,
                'sort_order' => $index + 1,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{debit: string, credit: string}
     */
    public function assertBalanced(array $rows): array
    {
        if (count($rows) < 2) {
            abort(422, 'A journal entry needs at least two lines.', ['X-Error-Code' => 'JOURNAL_ENTRY_TOO_FEW_LINES']);
        }

        $totals = $this->totals($rows);
        if (JournalMath::compare($totals['debit'], $totals['credit'], JournalMath::scale()) !== 0) {
            abort(422, 'Total debit must equal total credit.', ['X-Error-Code' => 'JOURNAL_ENTRY_UNBALANCED']);
        }

        return $totals;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{debit: string, credit: string}
     */
    public function totals(array $rows): array
    {
        $debit = '0';
        $credit = '0';
        foreach ($rows as $row) {
            $debit = JournalMath::add($debit, $row['debit']);
            $credit = JournalMath::add($credit, $row['credit']);
        }

        return ['debit' => JournalMath::store($debit), 'credit' => JournalMath::store($credit)];
    }

    /**
     * @param  list<AccountRole>  $accountRoles
     * @return array{0: ?string, 1: ?string}
     */
    private function party(JournalLineData $line, array $accountRoles, bool $manual, int $index): array
    {
        $required = null;
        foreach ($accountRoles as $role) {
            $required ??= $role->partyType();
        }

        $type = $line->partyType;
        $id = $line->partyId;
        if ($type === null && $id === null) {
            if ($required !== null) {
                $this->fail('Receivable and payable lines need a customer or supplier.', 'JOURNAL_LINE_PARTY_REQUIRED', $index);
            }

            return [null, null];
        }

        if ($type === null || $id === null
            || ! in_array($type, [JournalEntryLine::PARTY_CUSTOMER, JournalEntryLine::PARTY_SUPPLIER], true)
            || ($required !== null && $type !== $required)) {
            $this->fail('The line party is invalid.', 'JOURNAL_LINE_PARTY_INVALID', $index);
        }

        if ($manual) {
            $exists = $type === JournalEntryLine::PARTY_CUSTOMER
                ? Customer::query()->whereKey($id)->exists()
                : Supplier::query()->whereKey($id)->exists();
            if (! $exists) {
                $this->fail('The line party is invalid.', 'JOURNAL_LINE_PARTY_INVALID', $index);
            }
        }

        return [$type, $id];
    }

    /**
     * @return array<int, list<AccountRole>>
     */
    private function rolesByAccount(): array
    {
        $out = [];
        foreach (AccountMapping::query()->get(['role', 'account_id']) as $mapping) {
            $out[(int) $mapping->account_id][] = $mapping->role;
        }

        return $out;
    }

    private function fail(string $message, string $code, int $index): never
    {
        abort(422, 'Line '.($index + 1).': '.$message, ['X-Error-Code' => $code]);
    }
}
