<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Services\SalesInvoiceService;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use App\Modules\Sales\SalesOrder\Support\SalesOrderRules;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Sales\Support\CommercialLineBuilder;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ExchangeRateSnapshot;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        private readonly SalesOrderQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly SalesInvoiceService $salesInvoiceService,
    ) {}

    /**
     * @param  array{status?:string,customer_id?:string,search?:string,from?:string,to?:string}  $filters
     * @return LengthAwarePaginator<int, SalesOrder>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginate($filters, $perPage);
    }

    public function find(string $id): SalesOrder
    {
        $order = SalesOrder::query()
            ->with([
                'customer',
                'warehouse',
                'currency',
                'salesman',
                'paymentTerm',
                'salesQuotation',
                'createdByUser',
                'confirmedByUser',
                'cancelledByUser',
                'lines.item.itemType',
                'lines.item.vatGroup',
                'lines.itemUom.uom',
            ])
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $order->warehouse_id);

        return $order;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SalesOrder
    {
        $customer = SalesOrderRules::assertCustomer((string) $data['customer_id']);
        SalesOrderRules::assertWarehouse((int) $data['warehouse_id']);

        return DB::transaction(function () use ($data, $userId, $customer): SalesOrder {
            $order = SalesOrder::query()->create([
                ...$this->normalizeHeader($data, $customer, null),
                'sales_quotation_id' => isset($data['sales_quotation_id']) && $data['sales_quotation_id'] !== ''
                    ? (string) $data['sales_quotation_id']
                    : null,
                'status' => SalesOrderStatus::Draft,
                'created_by' => $userId,
                'order_number' => SequentialCodeGenerator::next(SalesOrder::class, 'order_number', 'SO-'),
            ]);

            $this->replaceLines($order, $data['lines'] ?? []);

            return $this->find($order->id);
        });
    }

    public function createFromQuotation(SalesQuotation $quotation, ?string $userId): SalesOrder
    {
        $warehouseId = $this->warehouseService->defaultWarehouseIdFor('sales');
        if ($warehouseId === null) {
            abort(422, 'Set a default sales warehouse before converting a quotation.', [
                'X-Error-Code' => 'SALES_ORDER_WAREHOUSE_REQUIRED',
            ]);
        }

        $quotation->loadMissing('lines');

        return $this->create([
            'customer_id' => $quotation->customer_id,
            'warehouse_id' => $warehouseId,
            'currency_id' => $quotation->currency_id,
            'exchange_rate' => $quotation->exchange_rate,
            'salesman_id' => $quotation->salesman_id,
            'payment_terms_id' => $quotation->payment_terms_id,
            'order_date' => now()->toDateString(),
            'requested_date' => $quotation->valid_until?->toDateString(),
            'notes' => $quotation->notes,
            'sales_quotation_id' => $quotation->id,
            'lines' => $quotation->lines->map(fn ($line): array => [
                'item_id' => $line->item_id,
                'item_uom_id' => $line->item_uom_id,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'discount_percent' => $line->discount_percent,
                'description' => $line->description,
                'notes' => $line->notes,
            ])->all(),
        ], $userId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SalesOrder $order, array $data): SalesOrder
    {
        return DB::transaction(function () use ($order, $data): SalesOrder {
            $order = $this->lockDraft($order);
            $customerId = array_key_exists('customer_id', $data)
                ? (string) $data['customer_id']
                : (string) $order->customer_id;
            $customer = SalesOrderRules::assertCustomer($customerId);
            if (array_key_exists('warehouse_id', $data)) {
                SalesOrderRules::assertWarehouse((int) $data['warehouse_id']);
            }
            $merged = array_merge($order->only([
                'customer_id',
                'warehouse_id',
                'currency_id',
                'salesman_id',
                'payment_terms_id',
                'order_date',
                'requested_date',
                'exchange_rate',
                'notes',
            ]), $data);
            $order->update($this->normalizeHeader($merged, $customer, $order));
            $this->recalculateTotals($order->fresh() ?? $order);

            return $this->find($order->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, SalesOrderLine>
     */
    public function syncLines(SalesOrder $order, array $lines): Collection
    {
        return DB::transaction(function () use ($order, $lines): Collection {
            $order = $this->lockDraft($order);
            $this->replaceLines($order, $lines);

            return SalesOrderLine::query()
                ->where('sales_order_id', $order->id)
                ->with(['item.itemType', 'item.vatGroup', 'itemUom.uom'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(SalesOrder $order): void
    {
        DB::transaction(function () use ($order): void {
            $order = $this->lockDraft($order);
            if (SalesQuotation::query()->where('sales_order_id', $order->id)->exists()) {
                abort(422, 'This sales order was created from a quotation and cannot be deleted.', [
                    'X-Error-Code' => 'SALES_ORDER_FROM_QUOTATION',
                ]);
            }
            $order->delete();
        });
    }

    public function confirm(SalesOrder $order, ?string $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $locked = $this->lockDraft($order);
            SalesOrderRules::assertCustomer((string) $locked->customer_id);
            $lines = SalesOrderLine::query()->where('sales_order_id', $locked->id)->orderBy('id')->get();
            if ($lines->isEmpty()) {
                abort(422, 'Cannot confirm a sales order without lines.', ['X-Error-Code' => 'SALES_ORDER_NO_LINES']);
            }
            foreach ($lines as $line) {
                if (bccomp((string) $line->quantity, '0', 6) <= 0 || bccomp((string) $line->base_quantity, '0', 6) <= 0) {
                    abort(422, 'Each sales order line needs a quantity above zero.', [
                        'X-Error-Code' => 'SALES_ORDER_LINE_INVALID_QUANTITY',
                    ]);
                }
            }
            $locked->update([
                'status' => SalesOrderStatus::Confirmed,
                'confirmed_by' => $userId,
                'confirmed_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function cancel(SalesOrder $order, ?string $userId): SalesOrder
    {
        return DB::transaction(function () use ($order, $userId): SalesOrder {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
            if ($locked->status === SalesOrderStatus::Cancelled) {
                abort(422, 'This sales order is already cancelled.', ['X-Error-Code' => 'SALES_ORDER_NOT_CONFIRMED']);
            }
            $lines = SalesOrderLine::query()
                ->where('sales_order_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($lines as $line) {
                if (bccomp((string) $line->invoiced_base_quantity, '0', 6) > 0) {
                    abort(422, 'A sales order with posted invoices cannot be cancelled.', [
                        'X-Error-Code' => 'SALES_ORDER_HAS_INVOICES',
                    ]);
                }
            }
            $locked->update([
                'status' => SalesOrderStatus::Cancelled,
                'cancelled_by' => $userId,
                'cancelled_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function remainingLines(SalesOrder $order): array
    {
        $this->warehouseService->assertVisibleById((int) $order->warehouse_id);
        $order->loadMissing(['lines.item', 'lines.itemUom.uom']);

        $rows = [];
        foreach ($order->lines as $line) {
            if (bccomp($line->remainingBaseQuantity(), '0', 6) <= 0) {
                continue;
            }
            $rows[] = [
                'sales_order_line_id' => $line->id,
                'item_id' => $line->item_id,
                'item_uom_id' => $line->item_uom_id,
                'quantity' => (string) $line->quantity,
                'base_quantity' => (string) $line->base_quantity,
                'invoiced_base_quantity' => (string) $line->invoiced_base_quantity,
                'remaining_base_quantity' => $line->remainingBaseQuantity(),
                'remaining_quantity' => $line->remainingQuantity(),
                'unit_price' => (string) $line->unit_price,
                'discount_percent' => (string) $line->discount_percent,
                'tax_rate' => (string) $line->tax_rate,
                'description' => $line->description,
                'item' => $line->item ? [
                    'id' => $line->item->id,
                    'item_code' => $line->item->item_code,
                    'name' => $line->item->name,
                ] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{sales_order_line_id:int|string, quantity?:numeric-string|float|int|null}>|null  $requested
     */
    public function createInvoice(SalesOrder $order, ?array $requested, ?string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($order, $requested, $userId): SalesInvoice {
            $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
            if (! in_array($locked->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::PartiallyInvoiced], true)) {
                abort(422, 'Only a confirmed sales order can be invoiced.', ['X-Error-Code' => 'SALES_ORDER_NOT_CONFIRMED']);
            }

            /** @var Collection<int, SalesOrderLine> $lines */
            $lines = SalesOrderLine::query()
                ->where('sales_order_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var list<array{0: SalesOrderLine, 1: string}> $selected */
            $selected = [];
            if ($requested === null || $requested === []) {
                foreach ($lines as $line) {
                    $remaining = $line->remainingQuantity();
                    if (bccomp($remaining, '0', 6) > 0) {
                        $selected[] = [$line, $remaining];
                    }
                }
            } else {
                foreach ($requested as $row) {
                    $line = $lines->get((int) $row['sales_order_line_id']);
                    if (! $line instanceof SalesOrderLine) {
                        abort(422, 'Invoice line does not match the sales order line.', [
                            'X-Error-Code' => 'SALES_INVOICE_ORDER_LINE_MISMATCH',
                        ]);
                    }
                    $quantity = isset($row['quantity']) && $row['quantity'] !== null && $row['quantity'] !== ''
                        ? number_format((float) $row['quantity'], 6, '.', '')
                        : $line->remainingQuantity();
                    if (bccomp($quantity, '0', 6) <= 0) {
                        continue;
                    }
                    $selected[] = [$line, $quantity];
                }
            }

            if ($selected === []) {
                abort(422, 'This sales order has nothing left to invoice.', [
                    'X-Error-Code' => 'SALES_ORDER_NOTHING_TO_INVOICE',
                ]);
            }

            return $this->salesInvoiceService->create([
                'customer_id' => $locked->customer_id,
                'warehouse_id' => $locked->warehouse_id,
                'currency_id' => $locked->currency_id,
                'exchange_rate' => $locked->exchange_rate,
                'salesman_id' => $locked->salesman_id,
                'payment_terms_id' => $locked->payment_terms_id,
                'invoice_date' => now()->toDateString(),
                'notes' => $locked->notes,
                'sales_order_id' => $locked->id,
                'lines' => array_map(fn (array $pair): array => [
                    'sales_order_line_id' => $pair[0]->id,
                    'item_id' => $pair[0]->item_id,
                    'item_uom_id' => $pair[0]->item_uom_id,
                    'quantity' => $pair[1],
                    'unit_price' => $pair[0]->unit_price,
                    'discount_percent' => $pair[0]->discount_percent,
                    'description' => $pair[0]->description,
                    'notes' => $pair[0]->notes,
                    'warehouse_id' => $locked->warehouse_id,
                ], $selected),
            ], $userId);
        });
    }

    private function lockDraft(SalesOrder $order): SalesOrder
    {
        $locked = SalesOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);
        SalesOrderRules::assertDraft($locked);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $data, Customer $customer, ?SalesOrder $existing): array
    {
        $orderDate = isset($data['order_date']) && $data['order_date'] !== ''
            ? Carbon::parse((string) $data['order_date'])->toDateString()
            : ($existing?->order_date?->toDateString() ?? now()->toDateString());

        $currencyId = isset($data['currency_id']) && $data['currency_id'] !== null && $data['currency_id'] !== ''
            ? (int) $data['currency_id']
            : ($existing !== null ? (int) $existing->currency_id : (int) (Currency::getPrimary()?->id ?? 0));

        $exchangeRate = ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            $currencyId,
            $data['exchange_rate'] ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
            'SALES_ORDER',
        );

        $salesmanId = array_key_exists('salesman_id', $data)
            ? ($data['salesman_id'] !== null && $data['salesman_id'] !== '' ? (string) $data['salesman_id'] : null)
            : ($existing?->salesman_id ?? $customer->salesman_id);

        $paymentTermsId = array_key_exists('payment_terms_id', $data)
            ? ($data['payment_terms_id'] !== null && $data['payment_terms_id'] !== '' ? (int) $data['payment_terms_id'] : null)
            : ($existing?->payment_terms_id !== null ? (int) $existing->payment_terms_id : $customer->payment_terms_id);

        $requestedDate = array_key_exists('requested_date', $data)
            ? ($data['requested_date'] !== null && $data['requested_date'] !== '' ? Carbon::parse((string) $data['requested_date'])->toDateString() : null)
            : $existing?->requested_date?->toDateString();

        return [
            'customer_id' => (string) $customer->id,
            'warehouse_id' => (int) ($data['warehouse_id'] ?? $existing?->warehouse_id),
            'currency_id' => $currencyId,
            'salesman_id' => $salesmanId,
            'payment_terms_id' => $paymentTermsId,
            'order_date' => $orderDate,
            'requested_date' => $requestedDate,
            'exchange_rate' => $exchangeRate,
            'notes' => CommercialLineBuilder::nullableString($data['notes'] ?? $existing?->notes),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesOrder $order, array $lines): void
    {
        $order->loadMissing('customer');
        $customer = $order->customer ?? SalesOrderRules::assertCustomer((string) $order->customer_id);
        $rows = CommercialLineBuilder::rows($lines, $customer, $order->order_date->toDateString(), 'SALES_ORDER');

        SalesOrderLine::query()->where('sales_order_id', $order->id)->delete();
        foreach ($rows as $row) {
            SalesOrderLine::query()->create([
                ...$row,
                'sales_order_id' => $order->id,
                'invoiced_base_quantity' => '0',
            ]);
        }

        $this->recalculateTotals($order);
    }

    private function recalculateTotals(SalesOrder $order): void
    {
        $rows = SalesOrderLine::query()->where('sales_order_id', $order->id)->get();
        $order->update(CommercialLineBuilder::totals($rows));
    }
}
