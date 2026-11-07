<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Reports\Support\PrimaryMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Open posted invoices aged from the due date. Drafts and reversed invoices are not open.
 */
class AgingReportService
{
    /** @var list<string> */
    private const BUCKETS = ['current', '1_30', '31_60', '61_90', '90_plus'];

    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly ReportBranchScope $branches,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function customers(?string $asOf, ?int $branchId, ?string $customerId = null): array
    {
        return $this->report(
            'sales_invoices',
            'customers',
            'customer_id',
            'customer_code',
            'invoice_number',
            'customer_id',
            $asOf,
            $branchId,
            $customerId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function suppliers(?string $asOf, ?int $branchId, ?string $supplierId = null): array
    {
        return $this->report(
            'purchase_invoices',
            'suppliers',
            'supplier_id',
            'supplier_code',
            'invoice_number',
            'supplier_id',
            $asOf,
            $branchId,
            $supplierId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function report(
        string $invoiceTable,
        string $partyTable,
        string $partyColumn,
        string $codeColumn,
        string $numberColumn,
        string $idKey,
        ?string $asOf,
        ?int $branchId,
        ?string $partyId,
    ): array {
        $asOf = $asOf ?? $this->fiscal->today();
        $scope = $this->branches->apply($branchId);
        $asOfDate = Carbon::parse($asOf)->startOfDay();

        $query = DB::table($invoiceTable.' as i')
            ->join($partyTable.' as p', 'p.id', '=', 'i.'.$partyColumn)
            ->where('i.status', 'posted')
            ->where('i.net_to_pay', '>', 0);
        $this->applyBranch($query, $scope['filters']);

        $documents = $query->orderBy('p.name')->orderBy('i.invoice_number')->get([
            'i.id',
            'i.'.$numberColumn.' as number',
            'i.'.$partyColumn.' as party_id',
            'p.'.$codeColumn.' as code',
            'p.name as name',
            'i.invoice_date',
            'i.due_on',
            'i.net_to_pay',
            'i.exchange_rate',
        ]);

        /** @var array<string, array<string, mixed>> $parties */
        $parties = [];
        $invoices = [];
        foreach ($documents as $document) {
            $due = $document->due_on ?: $document->invoice_date;
            $bucket = $this->bucket($due !== null ? (string) $due : $asOf, $asOfDate);
            $amount = JournalMath::store(PrimaryMoney::convert($document->net_to_pay, $document->exchange_rate));
            $key = (string) $document->party_id;
            if (! isset($parties[$key])) {
                $parties[$key] = [
                    $idKey => $key,
                    'code' => (string) ($document->code ?? ''),
                    'name' => (string) ($document->name ?? ''),
                    'current' => '0',
                    '1_30' => '0',
                    '31_60' => '0',
                    '61_90' => '0',
                    '90_plus' => '0',
                    'total' => '0',
                ];
            }
            $parties[$key][$bucket] = JournalMath::add($parties[$key][$bucket], $amount);
            $parties[$key]['total'] = JournalMath::add($parties[$key]['total'], $amount);

            if ($partyId !== null && $key === $partyId) {
                $invoices[] = [
                    'id' => (string) $document->id,
                    'number' => (string) ($document->number ?? ''),
                    'invoice_date' => $this->dateString($document->invoice_date),
                    'due_on' => $this->dateString($document->due_on),
                    'bucket' => $bucket,
                    'net_to_pay' => $amount,
                ];
            }
        }

        $rows = [];
        foreach ($parties as $party) {
            foreach ([...self::BUCKETS, 'total'] as $column) {
                $party[$column] = JournalMath::store($party[$column]);
            }
            $rows[] = $party;
        }
        usort($rows, fn (array $left, array $right): int => strcasecmp((string) $left['name'], (string) $right['name']));

        $report = [
            'as_of' => $asOf,
            'branch_id' => $scope['branch_id'],
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
        if ($partyId !== null) {
            $report[$idKey] = $partyId;
            $report['invoices'] = $invoices;
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyBranch(Builder $query, array $filters): void
    {
        if (isset($filters['branch_id'])) {
            $query->where('i.branch_id', $filters['branch_id']);

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

        $query->whereIn('i.branch_id', $ids);
    }

    private function bucket(string $due, Carbon $asOf): string
    {
        $daysPast = (int) round(Carbon::parse($due)->startOfDay()->diffInDays($asOf, false));
        if ($daysPast <= 0) {
            return 'current';
        }
        if ($daysPast <= 30) {
            return '1_30';
        }
        if ($daysPast <= 60) {
            return '31_60';
        }
        if ($daysPast <= 90) {
            return '61_90';
        }

        return '90_plus';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, string>
     */
    private function totals(array $rows): array
    {
        $totals = [
            'current' => '0',
            '1_30' => '0',
            '31_60' => '0',
            '61_90' => '0',
            '90_plus' => '0',
            'total' => '0',
        ];
        foreach ($rows as $row) {
            foreach ($totals as $column => $amount) {
                $totals[$column] = JournalMath::add($amount, $row[$column] ?? '0');
            }
        }
        foreach ($totals as $column => $amount) {
            $totals[$column] = JournalMath::store($amount);
        }

        return $totals;
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value)->toDateString();
    }
}
