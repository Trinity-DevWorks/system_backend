<?php

declare(strict_types=1);

namespace App\Modules\Customer\Services;

use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerLedgerEntry;
use Illuminate\Pagination\LengthAwarePaginator;

class CustomerLedgerService
{
    /**
     * Live balance in one currency: SUM(debit) − SUM(credit). Positive means customer owes.
     */
    public function balanceInCurrency(Customer $customer, int $currencyId): string
    {
        return $this->balanceInCurrencyForCustomerId($customer->id, $currencyId);
    }

    public function balanceInCurrencyForCustomerId(int $customerId, int $currencyId): string
    {
        $raw = CustomerLedgerEntry::query()
            ->where('customer_id', $customerId)
            ->where('currency_id', $currencyId)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as bal')
            ->value('bal');

        return $this->formatMoney($raw);
    }

    /**
     * @return array<int, string> currency_id => formatted balance
     */
    public function balancesPerCurrencyForCustomer(Customer $customer): array
    {
        $rows = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->groupBy('currency_id')
            ->selectRaw('currency_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as bal')
            ->pluck('bal', 'currency_id');

        $out = [];
        foreach ($rows as $currencyId => $raw) {
            $out[(int) $currencyId] = $this->formatMoney($raw);
        }

        return $out;
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, array<int, string>> customer_id => currency_id => balance
     */
    public function balancesGroupedByCustomerAndCurrency(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $rows = CustomerLedgerEntry::query()
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id', 'currency_id')
            ->selectRaw('customer_id, currency_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as bal')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $cid = (int) $row->customer_id;
            $curId = (int) $row->currency_id;
            $out[$cid][$curId] = $this->formatMoney($row->bal);
        }

        return $out;
    }

    public function postOpeningBalance(Customer $customer, int $currencyId, string $openingBalance, ?string $transactionDate = null): void
    {
        $amount = (float) $openingBalance;
        if (abs($amount) < 0.0000001) {
            return;
        }

        $date = $transactionDate ?? now()->toDateString();

        if ($amount > 0) {
            $this->insertEntry($customer, $currencyId, (string) $amount, '0', LedgerReferenceType::OpeningBalance, null, $date);
        } else {
            $this->insertEntry($customer, $currencyId, '0', (string) abs($amount), LedgerReferenceType::OpeningBalance, null, $date);
        }
    }

    public function replaceOpeningBalancePosting(Customer $customer, int $currencyId, string $openingBalance, ?string $transactionDate = null): void
    {
        $this->deleteOpeningEntries($customer, $currencyId);
        $this->postOpeningBalance($customer, $currencyId, $openingBalance, $transactionDate);
    }

    /**
     * @internal Used by invoicing / payments modules later.
     */
    public function postEntry(
        Customer $customer,
        int $currencyId,
        string $debit,
        string $credit,
        LedgerReferenceType $referenceType,
        ?int $referenceId,
        string $transactionDate
    ): CustomerLedgerEntry {
        $d = (float) $debit;
        $c = (float) $credit;
        if ($d <= 0 && $c <= 0) {
            throw new \InvalidArgumentException('Ledger entry must have a positive debit or credit.');
        }

        return $this->insertEntry($customer, $currencyId, $debit, $credit, $referenceType, $referenceId, $transactionDate);
    }

    /**
     * @return LengthAwarePaginator<int, CustomerLedgerEntry>
     */
    public function paginateForCustomer(Customer $customer, int $perPage = 25): LengthAwarePaginator
    {
        return CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->with('currency:id,code')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function deleteOpeningEntries(Customer $customer, int $currencyId): void
    {
        CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->where('currency_id', $currencyId)
            ->where('reference_type', LedgerReferenceType::OpeningBalance)
            ->delete();
    }

    private function insertEntry(
        Customer $customer,
        int $currencyId,
        string $debit,
        string $credit,
        LedgerReferenceType $referenceType,
        ?int $referenceId,
        string $transactionDate
    ): CustomerLedgerEntry {
        return CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
            'currency_id' => $currencyId,
            'debit' => $debit,
            'credit' => $credit,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'transaction_date' => $transactionDate,
        ]);
    }

    private function formatMoney(mixed $value): string
    {
        return number_format((float) $value, 4, '.', '');
    }
}
