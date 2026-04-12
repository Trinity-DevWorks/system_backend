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
     * Live balance: SUM(credit) − SUM(debit). Positive means amount owed to supplier (accounts payable).
     */
    public function balance(Supplier $supplier): string
    {
        $raw = SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->value('bal');

        return $this->formatMoney($raw);
    }

    /**
     * @param  list<int>  $supplierIds
     * @return array<int, string> supplier_id => balance
     */
    public function balancesForSupplierIds(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $rows = SupplierLedgerEntry::query()
            ->whereIn('supplier_id', $supplierIds)
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->pluck('bal', 'supplier_id');

        $out = [];
        foreach ($supplierIds as $id) {
            $out[$id] = $this->formatMoney($rows[$id] ?? 0);
        }

        return $out;
    }

    public function postOpeningBalance(Supplier $supplier, string $openingBalance): void
    {
        $amount = (float) $openingBalance;
        if (abs($amount) < 0.0000001) {
            return;
        }

        $today = now()->toDateString();

        if ($amount > 0) {
            $this->insertEntry($supplier, '0', (string) $amount, LedgerReferenceType::OpeningBalance, null, $today);
        } else {
            $this->insertEntry($supplier, (string) abs($amount), '0', LedgerReferenceType::OpeningBalance, null, $today);
        }
    }

    /**
     * @internal Used by purchasing / payments modules later.
     */
    public function postEntry(
        Supplier $supplier,
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

        return $this->insertEntry($supplier, $debit, $credit, $referenceType, $referenceId, $transactionDate);
    }

    /**
     * @return LengthAwarePaginator<int, SupplierLedgerEntry>
     */
    public function paginateForSupplier(Supplier $supplier, int $perPage = 25): LengthAwarePaginator
    {
        return SupplierLedgerEntry::query()
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    private function insertEntry(
        Supplier $supplier,
        string $debit,
        string $credit,
        LedgerReferenceType $referenceType,
        ?int $referenceId,
        string $transactionDate
    ): SupplierLedgerEntry {
        return SupplierLedgerEntry::query()->create([
            'supplier_id' => $supplier->id,
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
