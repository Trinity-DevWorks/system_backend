<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Currency\Models\Currency;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Customer and supplier ledger balances in the primary currency, up to a date.
 * Foreign invoices use the document rate. Receipts and payments use the same
 * per-allocation rates the journal used, so the check can catch a party
 * posting that never reached the general ledger.
 */
final class PartySubledger
{
    public function customers(string $asOf): string
    {
        return $this->balance(
            'customer_ledger_entries',
            'customer_id',
            'debit - credit',
            'sales_invoices',
            'customer_receipt_allocations',
            'customer_receipt_id',
            'sales_invoice_id',
            'customer',
            SourceDocuments::CUSTOMER_OPENING_BALANCE,
            $asOf,
        );
    }

    public function suppliers(string $asOf): string
    {
        return $this->balance(
            'supplier_ledger_entries',
            'supplier_id',
            'credit - debit',
            'purchase_invoices',
            'supplier_payment_allocations',
            'supplier_payment_id',
            'purchase_invoice_id',
            'supplier',
            SourceDocuments::SUPPLIER_OPENING_BALANCE,
            $asOf,
        );
    }

    private function balance(
        string $ledger,
        string $partyColumn,
        string $netSql,
        string $invoiceTable,
        string $allocationTable,
        string $allocationParent,
        string $allocationInvoice,
        string $partyType,
        string $openingSource,
        string $asOf,
    ): string {
        $primaryId = (int) (Currency::getPrimary()?->id ?? 0);
        $primary = DB::table($ledger)
            ->where('currency_id', $primaryId)
            ->where('transaction_date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM('.$netSql.'), 0) as net')
            ->value('net');
        $total = JournalMath::of($primary);

        $foreign = DB::table($ledger)
            ->where('currency_id', '!=', $primaryId)
            ->where('transaction_date', '<=', $asOf)
            ->get([$partyColumn, 'currency_id', 'debit', 'credit', 'reference_type', 'reference_id']);
        if ($foreign->isEmpty()) {
            return JournalMath::store($total);
        }

        $invoiceIds = $foreign->where('reference_type', 'invoice')->pluck('reference_id')->filter()->unique()->all();
        $invoiceRates = $invoiceIds === []
            ? []
            : DB::table($invoiceTable)->whereIn('id', $invoiceIds)->pluck('exchange_rate', 'id')->all();

        $paymentIds = $foreign->where('reference_type', 'payment')->pluck('reference_id')->filter()->unique()->values()->all();
        $allocations = $this->allocationBundles($allocationTable, $allocationParent, $allocationInvoice, $invoiceTable, $paymentIds);

        $noteType = $partyType === 'customer' ? 'credit_note' : 'debit_note';
        $noteTable = $partyType === 'customer' ? 'sales_credit_notes' : 'purchase_debit_notes';
        $noteIds = $foreign->where('reference_type', $noteType)->pluck('reference_id')->filter()->unique()->all();
        $noteRates = $noteIds === []
            ? []
            : DB::table($noteTable)->whereIn('id', $noteIds)->pluck('exchange_rate', 'id')->all();

        $refundTable = $partyType === 'customer' ? 'customer_refund_allocations' : 'supplier_refund_allocations';
        $refundParent = $partyType === 'customer' ? 'customer_refund_id' : 'supplier_refund_id';
        $refundNote = $partyType === 'customer' ? 'sales_credit_note_id' : 'purchase_debit_note_id';
        $refundIds = $foreign->where('reference_type', 'refund')->pluck('reference_id')->filter()->unique()->values()->all();
        $refunds = $this->allocationBundles($refundTable, $refundParent, $refundNote, $noteTable, $refundIds);

        foreach ($foreign as $entry) {
            $net = $netSql === 'debit - credit'
                ? JournalMath::sub($entry->debit, $entry->credit)
                : JournalMath::sub($entry->credit, $entry->debit);
            $primaryAmount = match ((string) $entry->reference_type) {
                'invoice' => $this->convert($net, (string) ($invoiceRates[$entry->reference_id] ?? '1')),
                'payment' => $this->scaleToBundle($net, $allocations[$entry->reference_id.'|'.$entry->currency_id] ?? null),
                $noteType => $this->convert($net, (string) ($noteRates[$entry->reference_id] ?? '1')),
                'refund' => $this->scaleToBundle($net, $refunds[$entry->reference_id.'|'.$entry->currency_id] ?? null),
                default => $this->convert($net, $this->openingRate($partyType, (string) $entry->{$partyColumn}, (int) $entry->currency_id, $openingSource)),
            };
            $total = JournalMath::add($total, $primaryAmount);
        }

        return JournalMath::store($total);
    }

    /**
     * @param  list<string>  $paymentIds
     * @return array<string, array{document: string, primary: string}>
     */
    private function allocationBundles(
        string $allocationTable,
        string $parentColumn,
        string $invoiceColumn,
        string $invoiceTable,
        array $paymentIds,
    ): array {
        if ($paymentIds === []) {
            return [];
        }

        $rows = DB::table($allocationTable.' as a')
            ->join($invoiceTable.' as i', 'i.id', '=', 'a.'.$invoiceColumn)
            ->whereIn('a.'.$parentColumn, $paymentIds)
            ->get(['a.'.$parentColumn.' as parent_id', 'a.applied_amount', 'i.currency_id', 'i.exchange_rate']);

        $out = [];
        foreach ($rows as $row) {
            $key = $row->parent_id.'|'.$row->currency_id;
            $document = JournalMath::add($out[$key]['document'] ?? '0', $row->applied_amount);
            $primary = JournalMath::add($out[$key]['primary'] ?? '0', $this->convert((string) $row->applied_amount, (string) $row->exchange_rate));
            $out[$key] = ['document' => $document, 'primary' => $primary];
        }

        return $out;
    }

    /**
     * @param  array{document: string, primary: string}|null  $bundle
     */
    private function scaleToBundle(string $net, ?array $bundle): string
    {
        if ($bundle === null || JournalMath::isZero($bundle['document'])) {
            return $this->convert($net, '1');
        }
        $ratio = bcdiv(JournalMath::of($bundle['primary']), JournalMath::of($bundle['document']), JournalMath::WORK_SCALE);

        return JournalMath::round(JournalMath::mul($net, $ratio));
    }

    private function openingRate(string $partyType, string $partyId, int $currencyId, string $sourceType): string
    {
        $rate = DB::table('journal_entry_lines as l')
            ->join('journal_entries as je', 'je.id', '=', 'l.journal_entry_id')
            ->where('l.party_type', $partyType)
            ->where('l.party_id', $partyId)
            ->where('l.currency_id', $currencyId)
            ->where('je.source_type', $sourceType)
            ->value('l.exchange_rate');

        return $rate !== null && $rate !== '' ? (string) $rate : '1';
    }

    private function convert(string $amount, string $rate): string
    {
        $rate = bcadd($rate !== '' ? $rate : '1', '0', ExchangeRateSnapshot::SCALE);

        return JournalMath::round(ExchangeRateSnapshot::toPrimary($amount, $rate, JournalMath::WORK_SCALE));
    }
}
