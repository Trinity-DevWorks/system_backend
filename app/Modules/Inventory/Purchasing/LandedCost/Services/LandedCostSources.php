<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Purchasing\Enums\GoodsReceiptStatus;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostSourceType;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostStatus;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Stock\Enums\StockMovementType;
use App\Modules\Inventory\Stock\Models\StockMovement;
use App\Modules\Reports\Support\PrimaryMoney;
use App\Modules\Treasury\Enums\TreasuryDocumentStatus;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Models\ExpenseVoucherLine;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * What a landed cost can use: posted receipts (and their stock lines) and open charges from bills.
 */
class LandedCostSources
{
    public function __construct(
        private readonly AccountMappingService $accountMappings,
    ) {}

    public function clearingAccountId(): ?int
    {
        try {
            return (int) $this->accountMappings->accountFor(AccountRole::LandedCostClearing)->id;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Posted goods receipts and direct purchase invoices that moved stock.
     *
     * @param  array{supplier_id?: string|null, from?: string|null, to?: string|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function receipts(array $filters): array
    {
        $out = [];

        $grns = GoodsReceipt::query()
            ->with('supplier:id,name')
            ->where('status', GoodsReceiptStatus::Posted)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('received_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('received_date', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('grn_number', 'ilike', '%'.$v.'%'))
            ->orderByDesc('received_date')
            ->limit(100)
            ->get();
        foreach ($grns as $grn) {
            $out[] = [
                'source_type' => LandedCostSourceType::GoodsReceipt->value,
                'source_id' => (string) $grn->id,
                'number' => $grn->grn_number,
                'date' => $grn->received_date?->toDateString(),
                'supplier' => $grn->supplier?->name,
                'value' => $this->movementValue('goods_receipt', (string) $grn->id),
            ];
        }

        $invoices = PurchaseInvoice::query()
            ->with('supplier:id,name')
            ->where('status', PurchaseInvoiceStatus::Posted)
            ->whereNull('goods_receipt_id')
            ->where('is_opening', false)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('stock_movements as m')
                ->where('m.reference_type', 'purchase_invoice')
                ->whereColumn('m.reference_id', 'purchase_invoices.id'))
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where('invoice_number', 'ilike', '%'.$v.'%'))
            ->orderByDesc('invoice_date')
            ->limit(100)
            ->get();
        foreach ($invoices as $invoice) {
            $out[] = [
                'source_type' => LandedCostSourceType::PurchaseInvoice->value,
                'source_id' => (string) $invoice->id,
                'number' => $invoice->invoice_number,
                'date' => $invoice->invoice_date?->toDateString(),
                'supplier' => $invoice->supplier?->name,
                'value' => $this->movementValue('purchase_invoice', (string) $invoice->id),
            ];
        }

        usort($out, fn (array $a, array $b): int => strcmp((string) $b['date'], (string) $a['date']));

        return $out;
    }

    /**
     * Header info for one receipt (number, date, supplier); null when it is not a posted receipt.
     *
     * @return array<string, mixed>|null
     */
    public function receiptHeader(string $sourceType, string $sourceId): ?array
    {
        if ($sourceType === LandedCostSourceType::GoodsReceipt->value) {
            $grn = GoodsReceipt::query()->with('supplier:id,name')->find($sourceId);

            return $grn === null ? null : [
                'number' => $grn->grn_number,
                'date' => $grn->received_date?->toDateString(),
                'supplier' => $grn->supplier?->name,
                'posted' => $grn->status === GoodsReceiptStatus::Posted,
            ];
        }
        $invoice = PurchaseInvoice::query()->with('supplier:id,name')->find($sourceId);

        return $invoice === null ? null : [
            'number' => $invoice->invoice_number,
            'date' => $invoice->invoice_date?->toDateString(),
            'supplier' => $invoice->supplier?->name,
            'posted' => $invoice->status === PurchaseInvoiceStatus::Posted && $invoice->goods_receipt_id === null && ! $invoice->is_opening,
        ];
    }

    /**
     * Stock lines of a receipt with the purchase movement each produced.
     *
     * @return list<array{receipt_source_type: string, receipt_line_id: int, source_movement_id: int|null, item_id: string, item_code: string|null, item_name: string, weight: string|null, volume: string|null, warehouse_id: int, lot_id: int|null, received_quantity: string, value: string}>
     */
    public function receiptLines(string $sourceType, string $sourceId): array
    {
        $refType = $sourceType === LandedCostSourceType::GoodsReceipt->value ? 'goods_receipt' : 'purchase_invoice';
        $movements = StockMovement::query()
            ->where('reference_type', $refType)
            ->where('reference_id', $sourceId)
            ->where('type', StockMovementType::Purchase->value)
            ->where('quantity_delta', '>', 0)
            ->orderBy('id')
            ->get()
            ->all();

        if ($sourceType === LandedCostSourceType::GoodsReceipt->value) {
            $header = GoodsReceipt::query()->find($sourceId);
            $lines = GoodsReceiptLine::query()->where('goods_receipt_id', $sourceId)->orderBy('id')->get();
            $warehouseFor = fn ($line): int => (int) $header?->warehouse_id;
        } else {
            $header = PurchaseInvoice::query()->find($sourceId);
            $lines = PurchaseInvoiceLine::query()->where('purchase_invoice_id', $sourceId)->orderBy('sort_order')->orderBy('id')->get();
            $warehouseFor = fn ($line): int => (int) ($line->warehouse_id ?? $header?->warehouse_id);
        }

        $items = Item::query()->whereIn('id', $lines->pluck('item_id')->unique()->all())->get()->keyBy('id');

        $out = [];
        foreach ($lines as $line) {
            $item = $items->get((string) $line->item_id);
            if ($item === null || ! $item->track_inventory || ! Decimal::isPositive((string) $line->base_quantity)) {
                continue;
            }
            $warehouseId = $warehouseFor($line);
            $lotId = $line->lot_id !== null ? (int) $line->lot_id : null;

            // Match the line to its purchase movement (same item / warehouse / lot, first unused).
            $movement = null;
            foreach ($movements as $index => $candidate) {
                if ((string) $candidate->item_id === (string) $line->item_id
                    && (int) $candidate->warehouse_id === $warehouseId
                    && ($candidate->lot_id !== null ? (int) $candidate->lot_id : null) === $lotId) {
                    $movement = $candidate;
                    unset($movements[$index]);

                    break;
                }
            }
            if ($movement === null) {
                continue;
            }

            $out[] = [
                'receipt_source_type' => $sourceType,
                'receipt_line_id' => (int) $line->id,
                'source_movement_id' => (int) $movement->id,
                'item_id' => (string) $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->name,
                'weight' => $item->weight !== null ? (string) $item->weight : null,
                'volume' => $item->volume !== null ? (string) $item->volume : null,
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'received_quantity' => Decimal::round((string) $movement->quantity_delta, 6),
                'value' => Decimal::round((string) $movement->value_delta, 4),
            ];
        }

        return $out;
    }

    /**
     * Charges not fully used yet: landed-cost item lines of posted purchase invoices and
     * clearing-account lines of posted expense vouchers.
     *
     * @param  array{supplier_id?: string|null, search?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function openCharges(array $filters, ?string $excludeLandedCostId = null): array
    {
        $out = [];

        $piLines = DB::table('purchase_invoice_lines')
            ->join('purchase_invoices as pi', 'pi.id', '=', 'purchase_invoice_lines.purchase_invoice_id')
            ->join('items as i', 'i.id', '=', 'purchase_invoice_lines.item_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'pi.supplier_id')
            ->where('i.is_landed_cost', true)
            ->where('pi.status', PurchaseInvoiceStatus::Posted->value)
            ->where('pi.is_opening', false)
            ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('pi.supplier_id', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('pi.invoice_number', 'ilike', '%'.$v.'%')->orWhere('i.name', 'ilike', '%'.$v.'%')))
            ->orderByDesc('pi.invoice_date')
            ->limit(200)
            ->get(['purchase_invoice_lines.id', 'purchase_invoice_lines.line_subtotal', 'purchase_invoice_lines.description', 'pi.id as document_id', 'pi.invoice_number', 'pi.invoice_date', 'pi.exchange_rate', 's.name as party', 'i.name as item_name']);
        foreach ($piLines as $row) {
            $amount = PrimaryMoney::convert($row->line_subtotal, $row->exchange_rate);
            $out[] = $this->chargeRow(LandedCostSourceType::PurchaseInvoiceLine->value, (int) $row->id, $amount, [
                'document_type' => 'purchase_invoice',
                'document_id' => (string) $row->document_id,
                'document_number' => $row->invoice_number,
                'date' => (string) $row->invoice_date,
                'party' => $row->party,
                'label' => $row->description ?: $row->item_name,
            ], $excludeLandedCostId);
        }

        $clearing = $this->clearingAccountId();
        if ($clearing !== null) {
            $voucherLines = DB::table('expense_voucher_lines')
                ->join('expense_vouchers as v', 'v.id', '=', 'expense_voucher_lines.expense_voucher_id')
                ->leftJoin('suppliers as s', 's.id', '=', 'v.supplier_id')
                ->where('expense_voucher_lines.account_id', $clearing)
                ->where('v.status', TreasuryDocumentStatus::Posted->value)
                ->when($filters['supplier_id'] ?? null, fn ($q, $v) => $q->where('v.supplier_id', $v))
                ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('v.voucher_number', 'ilike', '%'.$v.'%')->orWhere('expense_voucher_lines.description', 'ilike', '%'.$v.'%')))
                ->orderByDesc('v.voucher_date')
                ->limit(200)
                ->get(['expense_voucher_lines.id', 'expense_voucher_lines.amount', 'expense_voucher_lines.description', 'v.id as document_id', 'v.voucher_number', 'v.voucher_date', 'v.exchange_rate', 'v.payee', 's.name as party']);
            foreach ($voucherLines as $row) {
                $amount = PrimaryMoney::convert($row->amount, $row->exchange_rate);
                $out[] = $this->chargeRow(LandedCostSourceType::ExpenseVoucherLine->value, (int) $row->id, $amount, [
                    'document_type' => 'expense_voucher',
                    'document_id' => (string) $row->document_id,
                    'document_number' => $row->voucher_number,
                    'date' => (string) $row->voucher_date,
                    'party' => $row->party ?? $row->payee,
                    'label' => $row->description,
                ], $excludeLandedCostId);
            }
        }

        return array_values(array_filter($out, fn (array $r): bool => Decimal::isPositive($r['open_amount'])));
    }

    /**
     * One charge line (open or not) for a saved landed cost.
     *
     * @return array<string, mixed>|null
     */
    public function charge(string $sourceType, int $lineId, ?string $excludeLandedCostId = null): ?array
    {
        if ($sourceType === LandedCostSourceType::PurchaseInvoiceLine->value) {
            $line = PurchaseInvoiceLine::query()->with('item:id,name,is_landed_cost')->find($lineId);
            $invoice = $line !== null ? PurchaseInvoice::query()->with('supplier:id,name')->find($line->purchase_invoice_id) : null;
            if ($line === null || $invoice === null) {
                return null;
            }

            return $this->chargeRow($sourceType, $lineId, PrimaryMoney::convert($line->line_subtotal, $invoice->exchange_rate), [
                'document_type' => 'purchase_invoice',
                'document_id' => (string) $invoice->id,
                'document_number' => $invoice->invoice_number,
                'date' => $invoice->invoice_date?->toDateString(),
                'party' => $invoice->supplier?->name,
                'label' => $line->description ?: $line->item?->name,
                'eligible' => $invoice->status === PurchaseInvoiceStatus::Posted && ! $invoice->is_opening && (bool) $line->item?->is_landed_cost,
            ], $excludeLandedCostId);
        }

        $line = ExpenseVoucherLine::query()->find($lineId);
        $voucher = $line !== null ? ExpenseVoucher::query()->with('supplier:id,name')->find($line->expense_voucher_id) : null;
        if ($line === null || $voucher === null) {
            return null;
        }

        return $this->chargeRow($sourceType, $lineId, PrimaryMoney::convert($line->amount, $voucher->exchange_rate), [
            'document_type' => 'expense_voucher',
            'document_id' => (string) $voucher->id,
            'document_number' => $voucher->voucher_number,
            'date' => $voucher->voucher_date?->toDateString(),
            'party' => $voucher->supplier?->name ?? $voucher->payee,
            'label' => $line->description,
            'eligible' => $voucher->status === TreasuryDocumentStatus::Posted && (int) $line->account_id === $this->clearingAccountId(),
        ], $excludeLandedCostId);
    }

    /**
     * Amount of a bill line already used by posted landed costs.
     */
    public function usedAmount(string $sourceType, int $lineId, ?string $excludeLandedCostId = null): string
    {
        $used = DB::table('landed_cost_charges as c')
            ->join('landed_costs as l', 'l.id', '=', 'c.landed_cost_id')
            ->where('c.source_type', $sourceType)
            ->where('c.source_line_id', $lineId)
            ->where('l.status', LandedCostStatus::Posted->value)
            ->when($excludeLandedCostId !== null, fn ($q) => $q->where('l.id', '!=', $excludeLandedCostId))
            ->sum('c.amount');

        return Decimal::round((string) $used, 4);
    }

    private function movementValue(string $referenceType, string $referenceId): string
    {
        return Decimal::round((string) StockMovement::query()
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('type', StockMovementType::Purchase->value)
            ->sum('value_delta'), 4);
    }

    /**
     * @param  array<string, mixed>  $info
     * @return array<string, mixed>
     */
    private function chargeRow(string $sourceType, int $lineId, string $amount, array $info, ?string $excludeLandedCostId): array
    {
        $used = $this->usedAmount($sourceType, $lineId, $excludeLandedCostId);

        return [
            'source_type' => $sourceType,
            'source_line_id' => $lineId,
            'amount' => Decimal::round($amount, 4),
            'used_amount' => $used,
            'open_amount' => Decimal::round(Decimal::sub($amount, $used), 4),
            'eligible' => $info['eligible'] ?? true,
        ] + $info;
    }
}
