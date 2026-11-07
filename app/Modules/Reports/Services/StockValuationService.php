<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Services\AccountBalanceService;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportAmounts;
use Illuminate\Support\Facades\DB;

/**
 * Stock on hand valued at today's balances. Balances have no history, so a past date is not available.
 */
class StockValuationService
{
    public function __construct(
        private readonly FiscalCalendar $fiscal,
        private readonly JournalPostingService $journalPosting,
        private readonly AccountMappingService $mappings,
        private readonly AccountBalanceService $balances,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function report(?int $warehouseId, ?int $categoryId, bool $includeZero): array
    {
        $query = DB::table('stock_balances as b')
            ->join('items as i', 'i.id', '=', 'b.item_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->when($warehouseId !== null, fn ($q) => $q->where('b.warehouse_id', $warehouseId))
            ->when($categoryId !== null, fn ($q) => $q->where('i.category_id', $categoryId))
            ->groupBy('b.item_id', 'b.warehouse_id', 'i.item_code', 'i.name', 'w.name')
            ->orderBy('i.item_code')
            ->orderBy('w.name')
            ->selectRaw('b.item_id, b.warehouse_id, i.item_code, i.name as item_name, w.name as warehouse_name, SUM(b.quantity) as quantity, SUM(b.inventory_value) as inventory_value');

        $rows = [];
        $quantity = '0';
        $value = '0';
        foreach ($query->get() as $row) {
            $qty = bcadd((string) $row->quantity, '0', 6);
            $lineValue = JournalMath::store($row->inventory_value);
            $qtyZero = bccomp($qty, '0', 6) === 0;
            if (! $includeZero && $qtyZero && ReportAmounts::zero($lineValue)) {
                continue;
            }
            $unitCost = $qtyZero
                ? JournalMath::store(0)
                : JournalMath::store(bcdiv($lineValue, $qty, JournalMath::WORK_SCALE));
            $rows[] = [
                'item_id' => (string) $row->item_id,
                'item_code' => (string) ($row->item_code ?? ''),
                'item_name' => (string) ($row->item_name ?? ''),
                'warehouse_id' => (int) $row->warehouse_id,
                'warehouse_name' => (string) ($row->warehouse_name ?? ''),
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'inventory_value' => $lineValue,
            ];
            $quantity = bcadd($quantity, $qty, 6);
            $value = JournalMath::add($value, $lineValue);
        }

        $report = [
            'as_of' => $this->fiscal->today(),
            'warehouse_id' => $warehouseId,
            'category_id' => $categoryId,
            'rows' => $rows,
            'totals' => [
                'lines' => count($rows),
                'quantity' => bcadd($quantity, '0', 6),
                'inventory_value' => JournalMath::store($value),
            ],
        ];
        $check = $this->ledgerCheck();
        if ($check !== null) {
            $report['ledger_check'] = $check;
        }

        return $report;
    }

    public function companyValue(): string
    {
        return JournalMath::store(DB::table('stock_balances')->sum('inventory_value'));
    }

    /**
     * Inventory account versus every stock balance, the same comparison the balance sheet uses.
     * Filtered report rows do not change this check.
     *
     * @return array{ledger: string, stock: string, difference: string}|null
     */
    private function ledgerCheck(): ?array
    {
        if (! $this->journalPosting->enabled()) {
            return null;
        }

        $mapped = AccountMapping::query()->where('role', AccountRole::Inventory->value)->whereNotNull('account_id')->exists();
        if (! $mapped) {
            return null;
        }

        $inventory = $this->mappings->accountFor(AccountRole::Inventory);
        $snapshot = $this->balances->totals(null, $this->fiscal->today());
        $ledger = ReportAmounts::natural($inventory->type, $snapshot[$inventory->id] ?? []);
        $stock = $this->companyValue();

        return [
            'ledger' => $ledger,
            'stock' => $stock,
            'difference' => JournalMath::store(JournalMath::sub($ledger, $stock)),
        ];
    }
}
