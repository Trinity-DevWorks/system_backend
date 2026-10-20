<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportAmounts;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * VAT from the documents themselves. Journal lines only carry the tax amount,
 * not the taxable base, so the return is built from invoice lines.
 */
class VatReportService
{
    public function __construct(
        private readonly AccountBalanceService $balances,
        private readonly AccountMappingService $mappings,
        private readonly FiscalCalendar $fiscal,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(?string $from, ?string $to): array
    {
        $defaults = $this->fiscal->defaultPeriod();
        $from = $from ?? $defaults['from'];
        $to = $to ?? $defaults['to'];

        $output = $this->mergeGroups(
            $this->side('sales_invoice_lines', 'sales_invoices', 'sales_invoice_id', 'sales_invoice', $from, $to),
            $this->negate($this->side('sales_credit_note_lines', 'sales_credit_notes', 'sales_credit_note_id', 'sales_credit_note', $from, $to, 'credit_date')),
        );
        $input = $this->mergeGroups(
            $this->side('purchase_invoice_lines', 'purchase_invoices', 'purchase_invoice_id', 'purchase_invoice', $from, $to),
            $this->negate($this->side('purchase_debit_note_lines', 'purchase_debit_notes', 'purchase_debit_note_id', 'purchase_debit_note', $from, $to, 'debit_date')),
        );
        $outputVat = $this->sumVat($output);
        $inputVat = $this->sumVat($input);

        $movement = $this->balances->totals($from, $to, null, [
            'exclude_source_types' => [JournalEntry::SOURCE_VAT_SETTLEMENT],
        ]);
        $outputAccount = $this->mappings->accountFor(AccountRole::VatOutput);
        $inputAccount = $this->mappings->accountFor(AccountRole::VatInput);
        $outputLedger = ReportAmounts::natural($outputAccount->type, $movement[$outputAccount->id] ?? []);
        $inputLedger = ReportAmounts::natural($inputAccount->type, $movement[$inputAccount->id] ?? []);

        return [
            'from' => $from,
            'to' => $to,
            'output' => $output,
            'output_vat' => $outputVat,
            'input' => $input,
            'input_vat' => $inputVat,
            'net' => JournalMath::store(JournalMath::sub($outputVat, $inputVat)),
            'ledger_check' => [
                'output_document' => $outputVat,
                'output_ledger' => $outputLedger,
                'output_difference' => JournalMath::store(JournalMath::sub($outputVat, $outputLedger)),
                'input_document' => $inputVat,
                'input_ledger' => $inputLedger,
                'input_difference' => JournalMath::store(JournalMath::sub($inputVat, $inputLedger)),
            ],
        ];
    }

    /**
     * Posted and reversed invoices count on their invoice date. A reversal counts
     * again, negative, on the date of the reverse journal entry, so a document
     * posted and reversed in one period nets to zero.
     *
     * @return list<array{tax_rate: string, taxable_amount: string, vat: string}>
     */
    private function side(string $lineTable, string $invoiceTable, string $foreignKey, string $sourceType, string $from, string $to, string $dateColumn = 'invoice_date'): array
    {
        $positive = $this->grouped($lineTable, $invoiceTable, $foreignKey, null, $from, $to, $dateColumn);
        $negative = $this->grouped($lineTable, $invoiceTable, $foreignKey, $sourceType, $from, $to, $dateColumn);

        $buckets = [];
        foreach ($positive as $row) {
            $this->addBucket($buckets, $row, false);
        }
        foreach ($negative as $row) {
            $this->addBucket($buckets, $row, true);
        }

        $groups = [];
        foreach ($buckets as $rate => $bucket) {
            $taxable = JournalMath::store($bucket['taxable']);
            $vat = JournalMath::store($bucket['vat']);
            if (ReportAmounts::zero($taxable) && ReportAmounts::zero($vat)) {
                continue;
            }
            $groups[] = [
                'tax_rate' => (string) $rate,
                'taxable_amount' => $taxable,
                'vat' => $vat,
            ];
        }
        usort($groups, fn (array $a, array $b): int => JournalMath::compare($a['tax_rate'], $b['tax_rate']));

        return $groups;
    }

    /**
     * @return list<object>
     */
    private function grouped(string $lineTable, string $invoiceTable, string $foreignKey, ?string $reverseSource, string $from, string $to, string $dateColumn): array
    {
        $query = DB::table($lineTable.' as l')
            ->join($invoiceTable.' as i', 'i.id', '=', 'l.'.$foreignKey)
            ->whereIn('i.status', ['posted', 'reversed'])
            ->groupBy('l.tax_rate', 'i.id', 'i.exchange_rate')
            ->selectRaw('l.tax_rate as tax_rate, i.exchange_rate as exchange_rate, SUM(l.line_total - l.tax_amount) as taxable, SUM(l.tax_amount) as vat');

        if ($reverseSource === null) {
            $query->whereBetween('i.'.$dateColumn, [$from, $to]);
        } else {
            $query->join('journal_entries as je', function ($join) use ($reverseSource): void {
                $join->whereRaw('je.source_id = i.id::text')
                    ->where('je.source_type', '=', $reverseSource)
                    ->where('je.source_event', '=', JournalEntry::EVENT_REVERSE)
                    ->where('je.status', '!=', 'draft');
            })->whereBetween('je.entry_date', [$from, $to]);
        }

        return $query->get()->all();
    }

    /**
     * @param  array<string, array{taxable: string, vat: string}>  $buckets
     */
    private function addBucket(array &$buckets, object $row, bool $negative): void
    {
        $rate = JournalMath::store($row->tax_rate);
        $rateValue = bcadd((string) ($row->exchange_rate !== null && $row->exchange_rate !== '' ? $row->exchange_rate : '1'), '0', ExchangeRateSnapshot::SCALE);
        $taxable = JournalMath::round(ExchangeRateSnapshot::toPrimary((string) $row->taxable, $rateValue, JournalMath::WORK_SCALE));
        $vat = JournalMath::round(ExchangeRateSnapshot::toPrimary((string) $row->vat, $rateValue, JournalMath::WORK_SCALE));
        if ($negative) {
            $taxable = JournalMath::neg($taxable);
            $vat = JournalMath::neg($vat);
        }
        $buckets[$rate] = [
            'taxable' => JournalMath::add($buckets[$rate]['taxable'] ?? '0', $taxable),
            'vat' => JournalMath::add($buckets[$rate]['vat'] ?? '0', $vat),
        ];
    }

    /**
     * A credit note or debit note is the opposite of an invoice: the whole group
     * is negated, so a later reversal (already negative inside side()) becomes positive.
     *
     * @param  list<array{tax_rate: string, taxable_amount: string, vat: string}>  $groups
     * @return list<array{tax_rate: string, taxable_amount: string, vat: string}>
     */
    private function negate(array $groups): array
    {
        return array_map(fn (array $group): array => [
            'tax_rate' => $group['tax_rate'],
            'taxable_amount' => JournalMath::store(JournalMath::neg($group['taxable_amount'])),
            'vat' => JournalMath::store(JournalMath::neg($group['vat'])),
        ], $groups);
    }

    /**
     * @param  list<array{tax_rate: string, taxable_amount: string, vat: string}>  $left
     * @param  list<array{tax_rate: string, taxable_amount: string, vat: string}>  $right
     * @return list<array{tax_rate: string, taxable_amount: string, vat: string}>
     */
    private function mergeGroups(array $left, array $right): array
    {
        $buckets = [];
        foreach ([...$left, ...$right] as $group) {
            $rate = $group['tax_rate'];
            $buckets[$rate]['taxable'] = JournalMath::add($buckets[$rate]['taxable'] ?? '0', $group['taxable_amount']);
            $buckets[$rate]['vat'] = JournalMath::add($buckets[$rate]['vat'] ?? '0', $group['vat']);
        }

        $groups = [];
        foreach ($buckets as $rate => $bucket) {
            $taxable = JournalMath::store($bucket['taxable']);
            $vat = JournalMath::store($bucket['vat']);
            if (ReportAmounts::zero($taxable) && ReportAmounts::zero($vat)) {
                continue;
            }
            $groups[] = [
                'tax_rate' => (string) $rate,
                'taxable_amount' => $taxable,
                'vat' => $vat,
            ];
        }
        usort($groups, fn (array $a, array $b): int => JournalMath::compare($a['tax_rate'], $b['tax_rate']));

        return $groups;
    }

    /**
     * @param  list<array{tax_rate: string, taxable_amount: string, vat: string}>  $groups
     */
    private function sumVat(array $groups): string
    {
        $total = '0';
        foreach ($groups as $group) {
            $total = JournalMath::add($total, $group['vat']);
        }

        return JournalMath::store($total);
    }
}
