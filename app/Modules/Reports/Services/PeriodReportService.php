<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportAmounts;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Reports\Support\PrimaryMoney;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales and purchases by day, week, or month. Posted and reversed documents
 * count on their document date; a reversal counts again, negative, on the
 * reverse date so a document posted and reversed in one period nets to zero.
 */
class PeriodReportService
{
    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly ReportBranchScope $branches,
        private readonly JournalPostingService $journalPosting,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sales(?string $from, ?string $to, ?string $groupBy, ?int $branchId): array
    {
        return $this->report(
            'sales_invoices',
            'invoice_date',
            SalesInvoice::REFERENCE_TYPE,
            'sales_credit_notes',
            'credit_date',
            SalesCreditNote::REFERENCE_TYPE,
            $from,
            $to,
            $groupBy,
            $branchId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function purchases(?string $from, ?string $to, ?string $groupBy, ?int $branchId): array
    {
        return $this->report(
            'purchase_invoices',
            'invoice_date',
            PurchaseInvoice::REFERENCE_TYPE,
            'purchase_debit_notes',
            'debit_date',
            PurchaseDebitNote::REFERENCE_TYPE,
            $from,
            $to,
            $groupBy,
            $branchId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function report(
        string $invoiceTable,
        string $invoiceDate,
        string $invoiceSource,
        string $noteTable,
        string $noteDate,
        string $noteSource,
        ?string $from,
        ?string $to,
        ?string $groupBy,
        ?int $branchId,
    ): array {
        $defaults = $this->fiscal->currentMonthToDate();
        $from = $from ?? $defaults['from'];
        $to = $to ?? $defaults['to'];
        $groupBy = in_array($groupBy, ['day', 'week', 'month'], true) ? $groupBy : 'month';
        $scope = $this->branches->apply($branchId);
        $accountingOn = $this->journalPosting->enabled();

        /** @var array<string, array{document_count: int, gross: string, tax: string, credit_note_total: string}> $buckets */
        $buckets = [];
        $this->collect($buckets, $invoiceTable, $invoiceDate, $invoiceSource, $from, $to, $groupBy, $scope['filters'], $accountingOn, false);
        $this->collect($buckets, $noteTable, $noteDate, $noteSource, $from, $to, $groupBy, $scope['filters'], $accountingOn, true);

        ksort($buckets);
        $rows = [];
        foreach ($buckets as $period => $bucket) {
            $row = $this->row((string) $period, $bucket);
            if ($row['document_count'] === 0
                && ReportAmounts::zero($row['gross'])
                && ReportAmounts::zero($row['tax'])
                && ReportAmounts::zero($row['credit_note_total'])) {
                continue;
            }
            $rows[] = $row;
        }

        return [
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'from' => $from,
            'to' => $to,
            'group_by' => $groupBy,
            'branch_id' => $scope['branch_id'],
        ];
    }

    /**
     * @param  array<string, array{document_count: int, gross: string, tax: string, credit_note_total: string}>  $buckets
     * @param  array<string, mixed>  $filters
     */
    private function collect(
        array &$buckets,
        string $table,
        string $dateColumn,
        string $sourceType,
        string $from,
        string $to,
        string $groupBy,
        array $filters,
        bool $accountingOn,
        bool $isNote,
    ): void {
        $positive = $this->base($table, $filters)
            ->whereIn('d.status', ['posted', 'reversed'])
            ->whereBetween('d.'.$dateColumn, [$from, $to])
            ->selectRaw('d.id, d.exchange_rate, d.grand_total, d.tax_total, d.'.$dateColumn.' as event_date')
            ->get();
        foreach ($positive as $row) {
            $this->add($buckets, $row, $groupBy, 1, $isNote);
        }

        $negative = $this->base($table, $filters);
        if ($accountingOn) {
            $negative->join('journal_entries as je', function ($join) use ($sourceType): void {
                $join->whereRaw('je.source_id = d.id::text')
                    ->where('je.source_type', '=', $sourceType)
                    ->where('je.source_event', '=', JournalEntry::EVENT_REVERSE)
                    ->where('je.status', '!=', 'draft');
            })->whereBetween('je.entry_date', [$from, $to]);
            $rows = $negative
                ->selectRaw('d.id, d.exchange_rate, d.grand_total, d.tax_total, je.entry_date as event_date')
                ->get();
        } else {
            $rows = $negative
                ->where('d.status', 'reversed')
                ->whereDate('d.updated_at', '>=', $from)
                ->whereDate('d.updated_at', '<=', $to)
                ->selectRaw('d.id, d.exchange_rate, d.grand_total, d.tax_total, d.updated_at as event_date')
                ->get();
        }
        foreach ($rows as $row) {
            $this->add($buckets, $row, $groupBy, -1, $isNote);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function base(string $table, array $filters): Builder
    {
        $query = DB::table($table.' as d');
        $this->applyBranch($query, $filters);

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyBranch(Builder $query, array $filters): void
    {
        if (isset($filters['branch_id'])) {
            $query->where('d.branch_id', $filters['branch_id']);

            return;
        }

        if (! array_key_exists('branch_ids', $filters)) {
            return;
        }

        $ids = $filters['branch_ids'];
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn('d.branch_id', $ids);
    }

    /**
     * @param  array<string, array{document_count: int, gross: string, tax: string, credit_note_total: string}>  $buckets
     */
    private function add(array &$buckets, object $row, string $groupBy, int $sign, bool $isNote): void
    {
        $period = $this->bucket(Carbon::parse((string) $row->event_date)->toDateString(), $groupBy);
        $gross = PrimaryMoney::convert($row->grand_total, $row->exchange_rate);
        $tax = PrimaryMoney::convert($row->tax_total, $row->exchange_rate);
        if ($sign < 0) {
            $gross = JournalMath::neg($gross);
            $tax = JournalMath::neg($tax);
        }

        $bucket = $buckets[$period] ?? [
            'document_count' => 0,
            'gross' => '0',
            'tax' => '0',
            'credit_note_total' => '0',
        ];
        $bucket['document_count'] += $sign;
        if ($isNote) {
            $bucket['credit_note_total'] = JournalMath::add($bucket['credit_note_total'], $gross);
        } else {
            $bucket['gross'] = JournalMath::add($bucket['gross'], $gross);
            $bucket['tax'] = JournalMath::add($bucket['tax'], $tax);
        }
        $buckets[$period] = $bucket;
    }

    private function bucket(string $date, string $groupBy): string
    {
        $day = Carbon::parse($date)->startOfDay();

        return match ($groupBy) {
            'day' => $day->toDateString(),
            'week' => $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            default => $day->copy()->startOfMonth()->toDateString(),
        };
    }

    /**
     * @param  array{document_count: int, gross: string, tax: string, credit_note_total: string}  $bucket
     * @return array<string, mixed>
     */
    private function row(string $period, array $bucket): array
    {
        $gross = JournalMath::store($bucket['gross']);
        $tax = JournalMath::store($bucket['tax']);
        $credits = JournalMath::store($bucket['credit_note_total']);

        return [
            'period' => $period,
            'document_count' => $bucket['document_count'],
            'gross' => $gross,
            'tax' => $tax,
            'net' => JournalMath::store(JournalMath::sub($gross, $tax)),
            'credit_note_total' => $credits,
            'net_sales' => JournalMath::store(JournalMath::sub($gross, $credits)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $count = 0;
        $gross = '0';
        $tax = '0';
        $credits = '0';
        foreach ($rows as $row) {
            $count += (int) $row['document_count'];
            $gross = JournalMath::add($gross, $row['gross']);
            $tax = JournalMath::add($tax, $row['tax']);
            $credits = JournalMath::add($credits, $row['credit_note_total']);
        }
        $gross = JournalMath::store($gross);
        $tax = JournalMath::store($tax);
        $credits = JournalMath::store($credits);

        return [
            'document_count' => $count,
            'gross' => $gross,
            'tax' => $tax,
            'net' => JournalMath::store(JournalMath::sub($gross, $tax)),
            'credit_note_total' => $credits,
            'net_sales' => JournalMath::store(JournalMath::sub($gross, $credits)),
        ];
    }
}
