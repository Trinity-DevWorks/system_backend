<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Services;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Customer\Models\CustomerBalance;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefund;
use App\Modules\Inventory\Stock\Models\OpeningStock;
use App\Modules\Inventory\Stock\Models\Production;
use App\Modules\Inventory\Stock\Models\StockAdjustment;
use App\Modules\Inventory\Stock\Models\StockCount;
use App\Modules\Inventory\Stock\Models\StockTransferClosure;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerRefund\Models\CustomerRefund;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Supplier\Models\SupplierBalance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Posts journal entries for documents that existed before the accounting module was on.
 * Safe to re-run: anything that already has an entry is skipped.
 */
class AccountingBackfillService
{
    /**
     * model class => [date column, posting method]
     *
     * @var array<class-string<Model>, array{0: string, 1: string}>
     */
    private const DOCUMENTS = [
        OpeningStock::class => ['opening_date', 'openingStock'],
        GoodsReceipt::class => ['received_date', 'goodsReceipt'],
        PurchaseInvoice::class => ['invoice_date', 'purchaseInvoice'],
        SalesInvoice::class => ['invoice_date', 'salesInvoice'],
        SalesCreditNote::class => ['credit_date', 'backfillSalesCreditNote'],
        CustomerReceipt::class => ['payment_date', 'customerReceipt'],
        CustomerRefund::class => ['refund_date', 'customerRefund'],
        PurchaseDebitNote::class => ['debit_date', 'backfillPurchaseDebitNote'],
        SupplierPayment::class => ['payment_date', 'supplierPayment'],
        SupplierRefund::class => ['refund_date', 'supplierRefund'],
        StockAdjustment::class => ['adjustment_date', 'stockAdjustment'],
        StockCount::class => ['count_date', 'stockCount'],
        Production::class => ['production_date', 'production'],
    ];

    public function __construct(
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * @return int journal entries created
     */
    public function run(): int
    {
        $before = JournalEntry::query()->count();

        foreach ($this->openingBalances() as $balance) {
            DB::transaction(function () use ($balance): void {
                $balance instanceof CustomerBalance
                    ? $this->journalPostingService->customerOpeningBalance($balance)
                    : $this->journalPostingService->supplierOpeningBalance($balance);
            });
        }

        foreach (StockTransferClosure::query()->orderBy('closed_at')->orderBy('created_at')->get() as $closure) {
            DB::transaction(function () use ($closure): void {
                $this->journalPostingService->stockTransferClosure(
                    $closure,
                    $closure->created_by !== null ? (string) $closure->created_by : null,
                    $closure->closed_at?->toDateString(),
                );
            });
        }

        foreach ($this->documents() as [$model, $date, $method]) {
            DB::transaction(function () use ($model, $date, $method): void {
                $this->journalPostingService->{$method}($model, $model->posted_by ?? null, $date);

                if ($this->statusValue($model) === 'reversed') {
                    $reversedOn = $model->updated_at?->toDateString() ?? $date;
                    if ($model instanceof SalesCreditNote) {
                        $this->journalPostingService->reverseCreditApplications((string) $model->getKey(), null);
                    }
                    if ($model instanceof PurchaseDebitNote) {
                        $this->journalPostingService->reverseDebitApplications((string) $model->getKey(), null);
                    }
                    $this->journalPostingService->reverse(
                        constant($model::class.'::REFERENCE_TYPE'),
                        (string) $model->getKey(),
                        null,
                        max($reversedOn, $date),
                    );
                }
            });
        }

        return JournalEntry::query()->count() - $before;
    }

    /**
     * Posted and reversed documents of every type, oldest first.
     *
     * @return list<array{0: Model, 1: string, 2: string}>
     */
    private function documents(): array
    {
        $rows = [];
        foreach (self::DOCUMENTS as $class => [$dateColumn, $method]) {
            $class::query()
                ->whereIn('status', ['posted', 'reversed'])
                ->orderBy($dateColumn)
                ->orderBy('created_at')
                ->each(function (Model $model) use (&$rows, $dateColumn, $method): void {
                    $date = $model->{$dateColumn}?->toDateString();
                    if ($date === null) {
                        return;
                    }
                    $rows[] = [$model, $date, $method, (string) ($model->posted_at ?? $model->created_at)];
                });
        }

        usort($rows, fn (array $a, array $b): int => [$a[1], $a[3]] <=> [$b[1], $b[3]]);

        return array_map(fn (array $row): array => [$row[0], $row[1], $row[2]], $rows);
    }

    /**
     * @return list<CustomerBalance|SupplierBalance>
     */
    private function openingBalances(): array
    {
        $out = [];
        foreach ([CustomerBalance::class, SupplierBalance::class] as $class) {
            $class::query()->where('opening_balance', '!=', 0)->orderBy('id')->each(function (Model $balance) use (&$out): void {
                $out[] = $balance;
            });
        }

        return $out;
    }

    private function statusValue(Model $model): string
    {
        $status = $model->getAttribute('status');

        return $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
    }
}
