<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierLedgerEntry;
use Illuminate\Pagination\LengthAwarePaginator;

class SupplierLedgerService
{
    /**
     * Live balance in one currency: SUM(credit) − SUM(debit). Positive means amount owed to supplier (AP).
     */
    public function balanceInCurrency(Supplier $supplier, int $currencyId): string
    {
        return $this->balanceInCurrencyForSupplierId($supplier->id, $currencyId);
    }

    public function balanceInCurrencyForSupplierId(int $supplierId, int $currencyId): string
    {
        $raw = SupplierLedgerEntry::query()
            ->where('supplier_id', $supplierId)
            ->where('currency_id', $currencyId)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->value('bal');

        return $this->formatMoney($raw);
    }

    /**
     * @return array<int, string> currency_id => formatted balance
     */
    public function balancesPerCurrencyForSupplier(Supplier $supplier): array
    {
        $rows = SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->groupBy('currency_id')
            ->selectRaw('currency_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->pluck('bal', 'currency_id');

        $out = [];
        foreach ($rows as $currencyId => $raw) {
            $out[(int) $currencyId] = $this->formatMoney($raw);
        }

        return $out;
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array<int, string> supplier_id => balance in one currency
     */
    public function balancesForSupplierIdsInCurrency(array $supplierIds, int $currencyId): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $rows = SupplierLedgerEntry::query()
            ->whereIn('supplier_id', $supplierIds)
            ->where('currency_id', $currencyId)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->pluck('bal', 'supplier_id');

        $out = [];
        foreach ($supplierIds as $id) {
            $out[$id] = $this->formatMoney($rows[$id] ?? 0);
        }

        return $out;
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array<int, array<int, string>> supplier_id => currency_id => balance
     */
    public function balancesGroupedBySupplierAndCurrency(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $rows = SupplierLedgerEntry::query()
            ->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id', 'currency_id')
            ->selectRaw('supplier_id, currency_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $sid = (int) $row->supplier_id;
            $curId = (int) $row->currency_id;
            $out[$sid][$curId] = $this->formatMoney($row->bal);
        }

        return $out;
    }

    /**
     * Primary-currency balance for list views (first currency row or zero).
     *
     * @deprecated Prefer balancesPerCurrencyForSupplier; kept for callers expecting a single scalar.
     */
    public function balance(Supplier $supplier): string
    {
        $byCur = $this->balancesPerCurrencyForSupplier($supplier);
        if ($byCur === []) {
            return '0.0000';
        }

        return reset($byCur);
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array<int, string> supplier_id => balance (sum across currencies — legacy list helper)
     */
    public function balancesForSupplierIds(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $grouped = $this->balancesGroupedBySupplierAndCurrency($supplierIds);
        $out = [];
        foreach ($supplierIds as $id) {
            $rows = $grouped[$id] ?? [];
            $sum = array_sum(array_map(static fn (string $v): float => (float) $v, $rows));
            $out[$id] = $this->formatMoney($sum);
        }

        return $out;
    }

    public function postOpeningBalance(Supplier $supplier, int $currencyId, string $openingBalance, ?string $transactionDate = null): void
    {
        $amount = (float) $openingBalance;
        if (abs($amount) < 0.0000001) {
            return;
        }

        $date = $transactionDate ?? now()->toDateString();

        if ($amount > 0) {
            $this->insertEntry($supplier, $currencyId, '0', (string) $amount, LedgerReferenceType::OpeningBalance, null, $date);
        } else {
            $this->insertEntry($supplier, $currencyId, (string) abs($amount), '0', LedgerReferenceType::OpeningBalance, null, $date);
        }
    }

    public function replaceOpeningBalancePosting(Supplier $supplier, int $currencyId, string $openingBalance, ?string $transactionDate = null): void
    {
        $this->deleteOpeningEntries($supplier, $currencyId);
        $this->postOpeningBalance($supplier, $currencyId, $openingBalance, $transactionDate);
    }

    /**
     * @internal Used by purchasing / payments modules later.
     */
    public function postEntry(
        Supplier $supplier,
        int $currencyId,
        string $debit,
        string $credit,
        LedgerReferenceType $referenceType,
        ?int $referenceId,
        string $transactionDate
    ): SupplierLedgerEntry {
        $d = (float) $debit;
        $c = (float) $credit;
        if ($d <= 0 && $c <= 0) {
            throw new \InvalidArgumentException('Ledger entry must have a positive debit or credit.');
        }

        return $this->insertEntry($supplier, $currencyId, $debit, $credit, $referenceType, $referenceId, $transactionDate);
    }

    /**
     * @return LengthAwarePaginator<int, SupplierLedgerEntry>
     */
    public function paginateForSupplier(Supplier $supplier, int $perPage = 25): LengthAwarePaginator
    {
        return SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->with('currency:id,code')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function deleteOpeningEntries(Supplier $supplier, int $currencyId): void
    {
        SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->where('currency_id', $currencyId)
            ->where('reference_type', LedgerReferenceType::OpeningBalance)
            ->delete();
    }

    private function insertEntry(
        Supplier $supplier,
        int $currencyId,
        string $debit,
        string $credit,
        LedgerReferenceType $referenceType,
        ?int $referenceId,
        string $transactionDate
    ): SupplierLedgerEntry {
        return SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
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
