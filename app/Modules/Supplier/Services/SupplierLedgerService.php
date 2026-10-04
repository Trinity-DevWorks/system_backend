<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Services;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierLedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierLedgerService
{
    /**
     * Live balance in one currency: SUM(credit) − SUM(debit). Positive means amount owed to supplier (AP).
     */
    public function balanceInCurrency(Supplier $supplier, int $currencyId): string
    {
        return $this->balanceInCurrencyForSupplierId($supplier->id, $currencyId);
    }

    public function balanceInCurrencyForSupplierId(string $supplierId, int $currencyId): string
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
        ?string $referenceId,
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
            ->with('currency:id,code,symbol')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Statement, oldest first. Omit currency to include every currency.
     * Balance = credit − debit, tracked separately per currency.
     * Running balance continues across pages and includes activity before date_from.
     *
     * @return array{
     *     paginator: LengthAwarePaginator<int, SupplierLedgerEntry>,
     *     summary: array{opening_balance: string, period_debit: string, period_credit: string, closing_balance: string}|null,
     *     summaries: list<array{currency_id: int, currency_code: ?string, currency_symbol: ?string, opening_balance: string, period_debit: string, period_credit: string, closing_balance: string}>,
     *     running_balances: array<int, string>,
     *     reversed: array<int, true>,
     *     document_numbers: array<string, string>
     * }
     */
    public function statement(
        Supplier $supplier,
        ?int $currencyId,
        ?string $dateFrom,
        ?string $dateTo,
        int $perPage = 25,
    ): array {
        $partyId = (string) $supplier->id;
        $range = $this->scopedEntries($partyId, $currencyId);
        $this->applyDateRange($range, $dateFrom, $dateTo);

        $paginator = (clone $range)
            ->with('currency:id,code,symbol')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->paginate($perPage);

        $summaries = $this->currencySummaries($partyId, $currencyId, $dateFrom, $dateTo);

        return [
            'paginator' => $paginator,
            'summary' => $currencyId === null ? null : $this->flatSummary($summaries, $currencyId),
            'summaries' => $summaries,
            'running_balances' => $this->runningBalances($partyId, $dateFrom, $dateTo, $paginator->getCollection(), $summaries),
            'reversed' => $this->reversalIds($partyId, $currencyId, $paginator->getCollection()),
            'document_numbers' => $this->documentNumbers($paginator->getCollection()),
        ];
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
        ?string $referenceId,
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
        return PriceMath::normalize($value);
    }

    private function combine(string $left, string $right): string
    {
        return $this->formatMoney(bcadd($left, $right, 6));
    }

    /**
     * @return Builder<SupplierLedgerEntry>
     */
    private function scopedEntries(string $supplierId, ?int $currencyId): Builder
    {
        $query = SupplierLedgerEntry::query()->where('supplier_id', $supplierId);
        if ($currencyId !== null) {
            $query->where('currency_id', $currencyId);
        }

        return $query;
    }

    private function signedSumSql(): string
    {
        return 'COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0)';
    }

    private function rowDelta(SupplierLedgerEntry $entry): string
    {
        return bcsub((string) $entry->credit, (string) $entry->debit, 6);
    }

    private function closingBalance(string $opening, string $debit, string $credit): string
    {
        return $this->combine($opening, bcsub($credit, $debit, 6));
    }

    /**
     * @param  Builder<SupplierLedgerEntry>  $query
     */
    private function applyDateRange(Builder $query, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom !== null) {
            $query->where('transaction_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->where('transaction_date', '<=', $dateTo);
        }
    }

    /**
     * @param  Builder<SupplierLedgerEntry>  $query
     */
    private function signedBalance(Builder $query): string
    {
        $raw = (clone $query)
            ->selectRaw('COALESCE(SUM(credit), 0) - COALESCE(SUM(debit), 0) as bal')
            ->value('bal');

        return $this->formatMoney($raw);
    }

    /**
     * The second stored row for a document is the reversal. Opening balance has no reference id.
     *
     * @param  Collection<int, SupplierLedgerEntry>  $page
     * @return array<int, true>
     */
    private function reversalIds(string $supplierId, ?int $currencyId, Collection $page): array
    {
        $referenceIds = $page->pluck('reference_id')->filter()->unique()->values();
        if ($referenceIds->isEmpty()) {
            return [];
        }

        $rows = SupplierLedgerEntry::query()
            ->where('supplier_id', $supplierId)
            ->when($currencyId !== null, fn (Builder $query) => $query->where('currency_id', $currencyId))
            ->whereIn('reference_id', $referenceIds->all())
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get(['id', 'reference_type', 'reference_id']);

        $seen = [];
        $reversed = [];
        foreach ($rows as $row) {
            $type = $row->reference_type instanceof \BackedEnum ? $row->reference_type->value : (string) $row->reference_type;
            $key = $type.'|'.$row->reference_id;
            if (! isset($seen[$key])) {
                $seen[$key] = true;

                continue;
            }
            $reversed[(int) $row->id] = true;
        }

        return $reversed;
    }

    /**
     * @return list<array{currency_id: int, currency_code: ?string, currency_symbol: ?string, opening_balance: string, period_debit: string, period_credit: string, closing_balance: string}>
     */
    private function currencySummaries(string $partyId, ?int $currencyId, ?string $dateFrom, ?string $dateTo): array
    {
        /** @var array<int, string> $openingByCurrency */
        $openingByCurrency = [];
        if ($dateFrom !== null) {
            $rows = $this->scopedEntries($partyId, $currencyId)
                ->where('transaction_date', '<', $dateFrom)
                ->groupBy('currency_id')
                ->selectRaw('currency_id, '.$this->signedSumSql().' as bal')
                ->get();
            foreach ($rows as $row) {
                $openingByCurrency[(int) $row->currency_id] = $this->formatMoney($row->bal);
            }
        }

        $periodQuery = $this->scopedEntries($partyId, $currencyId);
        $this->applyDateRange($periodQuery, $dateFrom, $dateTo);
        $periodRows = $periodQuery
            ->groupBy('currency_id')
            ->selectRaw('currency_id, COALESCE(SUM(debit), 0) as period_debit, COALESCE(SUM(credit), 0) as period_credit')
            ->get();

        /** @var array<int, array{debit: string, credit: string}> $periodByCurrency */
        $periodByCurrency = [];
        foreach ($periodRows as $row) {
            $periodByCurrency[(int) $row->currency_id] = [
                'debit' => $this->formatMoney($row->period_debit),
                'credit' => $this->formatMoney($row->period_credit),
            ];
        }

        $ids = array_values(array_unique([...array_keys($openingByCurrency), ...array_keys($periodByCurrency)]));
        if ($currencyId !== null && ! in_array($currencyId, $ids, true)) {
            $ids[] = $currencyId;
        }
        sort($ids);

        $currencies = $ids === []
            ? collect()
            : DB::table('currencies')->whereIn('id', $ids)->get(['id', 'code', 'symbol'])->keyBy(fn ($row): int => (int) $row->id);

        $out = [];
        foreach ($ids as $id) {
            $opening = $openingByCurrency[$id] ?? $this->formatMoney(0);
            $debit = $periodByCurrency[$id]['debit'] ?? $this->formatMoney(0);
            $credit = $periodByCurrency[$id]['credit'] ?? $this->formatMoney(0);
            $currency = $currencies->get($id);
            $out[] = [
                'currency_id' => $id,
                'currency_code' => $currency?->code !== null ? (string) $currency->code : null,
                'currency_symbol' => $currency?->symbol !== null && $currency->symbol !== '' ? (string) $currency->symbol : null,
                'opening_balance' => $opening,
                'period_debit' => $debit,
                'period_credit' => $credit,
                'closing_balance' => $this->closingBalance($opening, $debit, $credit),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{currency_id: int, currency_code: ?string, currency_symbol: ?string, opening_balance: string, period_debit: string, period_credit: string, closing_balance: string}>  $summaries
     * @return array{opening_balance: string, period_debit: string, period_credit: string, closing_balance: string}
     */
    private function flatSummary(array $summaries, int $currencyId): array
    {
        foreach ($summaries as $row) {
            if ($row['currency_id'] === $currencyId) {
                return [
                    'opening_balance' => $row['opening_balance'],
                    'period_debit' => $row['period_debit'],
                    'period_credit' => $row['period_credit'],
                    'closing_balance' => $row['closing_balance'],
                ];
            }
        }

        $zero = $this->formatMoney(0);

        return [
            'opening_balance' => $zero,
            'period_debit' => $zero,
            'period_credit' => $zero,
            'closing_balance' => $zero,
        ];
    }

    /**
     * @param  Collection<int, SupplierLedgerEntry>  $page
     * @param  list<array{currency_id: int, opening_balance: string}>  $summaries
     * @return array<int, string>
     */
    private function runningBalances(string $partyId, ?string $dateFrom, ?string $dateTo, Collection $page, array $summaries): array
    {
        /** @var array<int, string> $openings */
        $openings = [];
        foreach ($summaries as $row) {
            $openings[(int) $row['currency_id']] = $row['opening_balance'];
        }

        $running = [];
        foreach ($page->groupBy(fn (SupplierLedgerEntry $entry): int => (int) $entry->currency_id) as $currencyId => $rows) {
            $first = $rows->first();
            if (! $first instanceof SupplierLedgerEntry) {
                continue;
            }
            $priorQuery = $this->scopedEntries($partyId, (int) $currencyId);
            $this->applyDateRange($priorQuery, $dateFrom, $dateTo);
            $date = $first->transaction_date->toDateString();
            $priorQuery->where(function (Builder $query) use ($first, $date): void {
                $query->where('transaction_date', '<', $date)
                    ->orWhere(function (Builder $sameDay) use ($first, $date): void {
                        $sameDay->where('transaction_date', $date)->where('id', '<', $first->id);
                    });
            });
            $cursor = $this->combine($openings[(int) $currencyId] ?? $this->formatMoney(0), $this->signedBalance($priorQuery));
            foreach ($rows as $entry) {
                $cursor = $this->combine($cursor, $this->rowDelta($entry));
                $running[(int) $entry->id] = $cursor;
            }
        }

        return $running;
    }

    /**
     * @param  Collection<int, SupplierLedgerEntry>  $entries
     * @return array<string, string> "{reference_type}|{reference_id}" => document number
     */
    private function documentNumbers(Collection $entries): array
    {
        $invoiceIds = [];
        $paymentIds = [];
        foreach ($entries as $entry) {
            if ($entry->reference_id === null) {
                continue;
            }
            $type = $entry->reference_type instanceof \BackedEnum ? $entry->reference_type->value : (string) $entry->reference_type;
            if ($type === LedgerReferenceType::PurchaseInvoice->value) {
                $invoiceIds[] = (string) $entry->reference_id;
            } elseif ($type === LedgerReferenceType::Payment->value) {
                $paymentIds[] = (string) $entry->reference_id;
            }
        }

        $out = [];
        if ($invoiceIds !== []) {
            $numbers = DB::table('purchase_invoices')->whereIn('id', array_values(array_unique($invoiceIds)))->pluck('invoice_number', 'id');
            foreach ($numbers as $id => $number) {
                if ($number !== null && $number !== '') {
                    $out[LedgerReferenceType::PurchaseInvoice->value.'|'.$id] = (string) $number;
                }
            }
        }
        if ($paymentIds !== []) {
            $numbers = DB::table('supplier_payments')->whereIn('id', array_values(array_unique($paymentIds)))->pluck('payment_number', 'id');
            foreach ($numbers as $id => $number) {
                if ($number !== null && $number !== '') {
                    $out[LedgerReferenceType::Payment->value.'|'.$id] = (string) $number;
                }
            }
        }

        return $out;
    }
}
