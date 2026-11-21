<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\DTOs\JournalLineData;
use App\Modules\Accounting\Models\Account;
use App\Support\ExchangeRateSnapshot;

/**
 * Collects signed amounts (positive = debit) for an automatic entry and turns them
 * into balanced journal lines in the primary currency.
 */
final class JournalBuilder
{
    /** @var list<array{account: Account, primary: string, currency_id: int, rate: string, amount_currency: string, party_type: ?string, party_id: ?string, description: ?string}> */
    private array $lines = [];

    public function __construct(
        private readonly int $primaryCurrencyId,
    ) {}

    /**
     * Amount in a document currency, converted with the document rate (1 primary = rate × currency).
     */
    public function foreign(
        Account $account,
        mixed $signedAmount,
        int $currencyId,
        mixed $rate,
        ?string $partyType = null,
        ?string $partyId = null,
        ?string $description = null,
    ): self {
        $amount = JournalMath::of($signedAmount);
        $rate = JournalMath::isPositive($rate, ExchangeRateSnapshot::SCALE) ? (string) $rate : '1';
        if ($currencyId === $this->primaryCurrencyId) {
            $rate = '1';
        }
        $primary = JournalMath::round(ExchangeRateSnapshot::toPrimary($amount, bcadd($rate, '0', ExchangeRateSnapshot::SCALE), JournalMath::WORK_SCALE));

        return $this->push($account, $primary, $currencyId, $rate, JournalMath::store(JournalMath::round($amount, JournalMath::STORAGE_SCALE)), $partyType, $partyId, $description);
    }

    /**
     * Amount already in the primary currency (stock values).
     */
    public function primary(
        Account $account,
        mixed $signedAmount,
        ?string $partyType = null,
        ?string $partyId = null,
        ?string $description = null,
    ): self {
        $primary = JournalMath::round($signedAmount);

        return $this->push($account, $primary, $this->primaryCurrencyId, '1', JournalMath::store($primary), $partyType, $partyId, $description);
    }

    /**
     * Signed primary total of the lines so far (debit − credit).
     */
    public function net(): string
    {
        $net = '0';
        foreach ($this->lines as $line) {
            $net = JournalMath::add($net, $line['primary']);
        }

        return $net;
    }

    /**
     * Post whatever keeps the entry from balancing to the given account.
     */
    public function balanceTo(Account $account, ?string $description = null): self
    {
        $net = $this->net();
        if (! JournalMath::isZero($net)) {
            $this->primary($account, JournalMath::neg($net), null, null, $description);
        }

        return $this;
    }

    /**
     * @return list<JournalLineData> empty when fewer than two lines carry an amount
     */
    public function lines(Account $roundingAccount): array
    {
        $this->balanceTo($roundingAccount, 'Rounding');

        $out = [];
        foreach ($this->lines as $line) {
            if (JournalMath::isZero($line['primary'])) {
                continue;
            }
            $debit = JournalMath::isPositive($line['primary']) ? $line['primary'] : '0';
            $credit = JournalMath::isNegative($line['primary']) ? JournalMath::abs($line['primary']) : '0';
            $out[] = new JournalLineData(
                accountId: (int) $line['account']->id,
                debit: $debit,
                credit: $credit,
                currencyId: $line['currency_id'],
                exchangeRate: $line['rate'],
                amountCurrency: $line['amount_currency'],
                partyType: $line['party_type'],
                partyId: $line['party_id'],
                description: $line['description'],
            );
        }

        return count($out) >= 2 ? $out : [];
    }

    private function push(Account $account, string $primary, int $currencyId, string $rate, string $amountCurrency, ?string $partyType, ?string $partyId, ?string $description): self
    {
        if (JournalMath::isZero($primary)) {
            return $this;
        }

        $this->lines[] = [
            'account' => $account,
            'primary' => $primary,
            'currency_id' => $currencyId,
            'rate' => $rate,
            'amount_currency' => $amountCurrency,
            'party_type' => $partyType,
            'party_id' => $partyId,
            'description' => $description,
        ];

        return $this;
    }
}
