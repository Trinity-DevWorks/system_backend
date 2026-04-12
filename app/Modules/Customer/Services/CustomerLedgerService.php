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
     * Live balance: SUM(debit) − SUM(credit). Positive means customer owes.
     */
    public function balance(Customer $customer): string
    {
        $raw = CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as bal')
            ->value('bal');

        return $this->formatMoney($raw);
    }

    /**
     * @param  list<int>  $customerIds
     * @return array<int, string> customer_id => balance
     */
    public function balancesForCustomerIds(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $rows = CustomerLedgerEntry::query()
            ->whereIn('customer_id', $customerIds)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as bal')
            ->pluck('bal', 'customer_id');

        $out = [];
        foreach ($customerIds as $id) {
            $out[$id] = $this->formatMoney($rows[$id] ?? 0);
        }

        return $out;
    }

    public function postOpeningBalance(Customer $customer, string $openingBalance): void
    {
        $amount = (float) $openingBalance;
        if (abs($amount) < 0.0000001) {
            return;
        }

        $today = now()->toDateString();

        if ($amount > 0) {
            $this->insertEntry($customer, (string) $amount, '0', LedgerReferenceType::OpeningBalance, null, $today);
        } else {
            $this->insertEntry($customer, '0', (string) abs($amount), LedgerReferenceType::OpeningBalance, null, $today);
        }
    }

    /**
     * @internal Used by invoicing / payments modules later.
     */
    public function postEntry(
        Customer $customer,
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

        return $this->insertEntry($customer, $debit, $credit, $referenceType, $referenceId, $transactionDate);
    }

    /**
     * @return LengthAwarePaginator<int, CustomerLedgerEntry>
     */
    public function paginateForCustomer(Customer $customer, int $perPage = 25): LengthAwarePaginator
    {
        return CustomerLedgerEntry::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function insertEntry(
        Customer $customer,
        string $debit,
        string $credit,
        LedgerReferenceType $referenceType,
        ?int $referenceId,
        string $transactionDate
    ): CustomerLedgerEntry {
        return CustomerLedgerEntry::query()->create([
            'customer_id' => $customer->id,
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
