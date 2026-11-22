<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use Illuminate\Support\Facades\DB;

/**
 * Where each automatic source type lives, for showing its document number.
 */
final class SourceDocuments
{
    public const CASH_BANK_OPENING_BALANCE = 'cash_bank_opening_balance';

    public const DEFERRAL_RECOGNITION = 'deferral_recognition';

    /** @var array<string, array{table: string, number: string}> */
    private const DOCUMENTS = [
        'sales_invoice' => ['table' => 'sales_invoices', 'number' => 'invoice_number'],
        'sales_credit_note' => ['table' => 'sales_credit_notes', 'number' => 'credit_note_number'],
        'customer_receipt' => ['table' => 'customer_receipts', 'number' => 'receipt_number'],
        'customer_cheque' => ['table' => 'customer_cheques', 'number' => 'cheque_number'],
        'delivery_note' => ['table' => 'delivery_notes', 'number' => 'delivery_number'],
        'landed_cost' => ['table' => 'landed_costs', 'number' => 'landed_cost_number'],
        'treasury_transfer' => ['table' => 'treasury_transfers', 'number' => 'transfer_number'],
        'expense_voucher' => ['table' => 'expense_vouchers', 'number' => 'voucher_number'],
        'customer_refund' => ['table' => 'customer_refunds', 'number' => 'refund_number'],
        'goods_receipt' => ['table' => 'goods_receipts', 'number' => 'grn_number'],
        'purchase_invoice' => ['table' => 'purchase_invoices', 'number' => 'invoice_number'],
        'purchase_debit_note' => ['table' => 'purchase_debit_notes', 'number' => 'debit_note_number'],
        'supplier_payment' => ['table' => 'supplier_payments', 'number' => 'payment_number'],
        'supplier_refund' => ['table' => 'supplier_refunds', 'number' => 'refund_number'],
        'stock_adjustment' => ['table' => 'stock_adjustments', 'number' => 'adj_number'],
        'stock_revaluation' => ['table' => 'stock_revaluations', 'number' => 'srv_number'],
        'stock_count' => ['table' => 'stock_counts', 'number' => 'cnt_number'],
        'stock_transfer_closure' => ['table' => 'stock_transfer_closures', 'number' => 'closure_number'],
        'opening_stock' => ['table' => 'opening_stocks', 'number' => 'os_number'],
        'production' => ['table' => 'productions', 'number' => 'prd_number'],
        'stock_transfer' => ['table' => 'stock_transfers', 'number' => 'transfer_number'],
        'stock_transfer_receipt' => ['table' => 'stock_transfer_receipts', 'number' => 'receipt_number'],
        'bundle_explosion' => ['table' => 'bundle_explosions', 'number' => 'bex_number'],
        'purchase_order' => ['table' => 'purchase_orders', 'number' => 'po_number'],
        'fixed_asset' => ['table' => 'fixed_assets', 'number' => 'asset_number'],
        'fixed_asset_disposal' => ['table' => 'fixed_assets', 'number' => 'disposal_number'],
        // Opening entry of a cash/bank account: shows the account code.
        self::CASH_BANK_OPENING_BALANCE => ['table' => 'cash_bank_accounts', 'number' => 'code'],
        'deferral_schedule' => ['table' => 'deferral_schedules', 'number' => 'schedule_number'],
    ];

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [...array_keys(self::DOCUMENTS), self::DEFERRAL_RECOGNITION];
    }

    /**
     * Document numbers keyed by "type:id".
     *
     * @param  iterable<array{0: string, 1: ?string}>  $refs
     * @return array<string, string>
     */
    public static function numbers(iterable $refs): array
    {
        $byType = [];
        foreach ($refs as [$type, $id]) {
            if ($id !== null && $id !== '') {
                $byType[$type][$id] = true;
            }
        }

        $out = [];
        foreach ($byType as $type => $ids) {
            $ids = array_keys($ids);
            if ($type === 'fixed_asset_depreciation') {
                $runIds = [];
                foreach ($ids as $id) {
                    $runIds[explode(':', (string) $id, 2)[0]] = true;
                }
                $numbers = DB::table('fixed_asset_depreciation_runs')->whereIn('id', array_keys($runIds))->pluck('run_number', 'id');
                foreach ($ids as $id) {
                    $runId = explode(':', (string) $id, 2)[0];
                    $number = $numbers[$runId] ?? null;
                    if ($number !== null && $number !== '') {
                        $out[$type.':'.$id] = (string) $number;
                    }
                }

                continue;
            }

            if (isset(self::DOCUMENTS[$type])) {
                $doc = self::DOCUMENTS[$type];
                foreach (DB::table($doc['table'])->whereIn('id', $ids)->get(['id', $doc['number'].' as number']) as $row) {
                    $out[$type.':'.$row->id] = (string) ($row->number ?? '');
                }

                continue;
            }

            $application = match ($type) {
                'sales_credit_note_apply' => ['sales_credit_notes', 'credit_note_number'],
                'purchase_debit_note_apply' => ['purchase_debit_notes', 'debit_note_number'],
                self::DEFERRAL_RECOGNITION => ['deferral_schedules', 'schedule_number'],
                default => null,
            };
            if ($application !== null) {
                [$table, $numberColumn] = $application;
                $noteIds = [];
                foreach ($ids as $id) {
                    $noteIds[explode(':', (string) $id, 2)[0]] = true;
                }
                $numbers = DB::table($table)->whereIn('id', array_keys($noteIds))->pluck($numberColumn, 'id');
                foreach ($ids as $id) {
                    $noteId = explode(':', (string) $id, 2)[0];
                    $number = $numbers[$noteId] ?? null;
                    if ($number !== null && $number !== '') {
                        $out[$type.':'.$id] = (string) $number;
                    }
                }
            }
        }

        return $out;
    }
}
