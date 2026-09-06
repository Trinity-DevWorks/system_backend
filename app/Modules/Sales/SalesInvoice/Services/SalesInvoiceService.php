<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Item\Models\BundleItem;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\DTOs\StockMovementData;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockMovementService;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceLineQuantity;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    public function __construct(
        private readonly SalesInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly StockMovementService $stockMovementService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * @param  array{
     *   status?:string,
     *   customer_id?:string,
     *   search?:string,
     *   from?:string,
     *   to?:string
     * }  $filters
     * @return LengthAwarePaginator<int, SalesInvoice>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginate($filters, $perPage);
    }

    public function find(string $id): SalesInvoice
    {
        $invoice = SalesInvoice::query()
            ->with([
                'customer',
                'warehouse',
                'currency',
                'salesman',
                'paymentMethod',
                'paymentTerm',
                'createdByUser',
                'postedByUser',
                'lines' => fn ($query) => $query->orderBy('sort_order')->orderBy('id'),
                'lines.item',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
            ])
            ->findOrFail($id);

        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SalesInvoice
    {
        $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
        SalesInvoiceRules::assertWarehouse((int) $data['warehouse_id']);

        return DB::transaction(function () use ($data, $userId, $customer): SalesInvoice {
            $header = $this->normalizeHeader($data, $customer, null);

            $invoice = SalesInvoice::query()->create([
                ...$header,
                'status' => SalesInvoiceStatus::Draft,
                'paid_total' => PriceMath::normalize(0),
                'created_by' => $userId,
            ]);

            $invoice->update([
                'invoice_number' => SequentialCodeGenerator::next(SalesInvoice::class, 'invoice_number', 'SI-'),
            ]);

            if (! empty($data['lines'])) {
                $this->replaceLines($invoice, $data['lines']);
            } else {
                $this->recalculateTotals($invoice);
            }

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SalesInvoice $invoice, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $data): SalesInvoice {
            $invoice = $this->lockDraft($invoice);
            $customerId = array_key_exists('customer_id', $data)
                ? (string) $data['customer_id']
                : (string) $invoice->customer_id;
            $customer = SalesInvoiceRules::assertCustomer($customerId);

            if (array_key_exists('warehouse_id', $data)) {
                SalesInvoiceRules::assertWarehouse((int) $data['warehouse_id']);
            }

            $merged = array_merge($invoice->only([
                'customer_id',
                'warehouse_id',
                'currency_id',
                'salesman_id',
                'payment_method_id',
                'payment_terms_id',
                'invoice_date',
                'due_on',
                'exchange_rate',
                'reference_2',
                'billing_address',
                'shipping_address',
                'adjustment',
                'notes',
            ]), $data);

            $header = $this->normalizeHeader($merged, $customer, $invoice);
            $invoice->update($header);
            $this->recalculateTotals($invoice->fresh() ?? $invoice);

            return $this->find($invoice->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function syncLines(SalesInvoice $invoice, array $lines): Collection
    {
        return DB::transaction(function () use ($invoice, $lines): Collection {
            $invoice = $this->lockDraft($invoice);
            $this->replaceLines($invoice, $lines);

            return SalesInvoiceLine::query()
                ->where('sales_invoice_id', $invoice->id)
                ->with(['item', 'itemUom.uom', 'warehouse', 'lot'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(SalesInvoice $invoice): void
    {
        SalesInvoiceRules::assertDraft($invoice);
        $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
        $invoice->delete();
    }

    public function post(SalesInvoice $invoice, ?string $userId): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $userId): SalesInvoice {
            $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            SalesInvoiceRules::assertPostable($locked);
            $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

            $this->recalculateTotals($locked);
            $locked->refresh();

            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);

            if (bccomp((string) $locked->grand_total, '0', 4) > 0) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    (int) $locked->currency_id,
                    (string) $locked->grand_total,
                    '0',
                    LedgerReferenceType::Invoice,
                    (string) $locked->id,
                    $locked->invoice_date->toDateString(),
                );
            }

            $lines = SalesInvoiceLine::query()
                ->where('sales_invoice_id', $locked->id)
                ->with(['item.itemType', 'lot'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lines as $line) {
                $this->postLineStock($locked, $line, $userId);
            }

            $locked->update([
                'status' => SalesInvoiceStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * Warehouses (and lots) that currently hold the item — for line warehouse pickers.
     *
     * @return list<array<string, mixed>>
     */
    public function itemAvailability(string $itemId): array
    {
        Item::query()->findOrFail($itemId);

        $query = StockBalance::query()
            ->where('item_id', $itemId)
            ->where('quantity', '>', 0)
            ->with([
                'warehouse:id,name,shortcut_name,is_active',
                'lot:id,lot_number,expiry_date,item_id',
            ]);

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        $rows = $query->get();
        $byWarehouse = [];
        foreach ($rows as $row) {
            $wid = (int) $row->warehouse_id;
            if (! isset($byWarehouse[$wid])) {
                $byWarehouse[$wid] = [
                    'warehouse_id' => $wid,
                    'on_hand' => '0',
                    'warehouse' => $row->warehouse ? [
                        'id' => $row->warehouse->id,
                        'name' => $row->warehouse->name,
                        'shortcut_name' => $row->warehouse->shortcut_name,
                        'is_active' => (bool) $row->warehouse->is_active,
                    ] : null,
                    'lots' => [],
                ];
            }
            $byWarehouse[$wid]['on_hand'] = bcadd($byWarehouse[$wid]['on_hand'], (string) $row->quantity, 6);
            if ($row->lot_id && $row->lot) {
                $byWarehouse[$wid]['lots'][] = [
                    'id' => $row->lot->id,
                    'lot_number' => $row->lot->lot_number,
                    'expiry_date' => $row->lot->expiry_date?->toDateString(),
                    'on_hand' => (string) $row->quantity,
                ];
            }
        }

        return array_values($byWarehouse);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $data, Customer $customer, ?SalesInvoice $existing): array
    {
        $invoiceDate = isset($data['invoice_date']) && $data['invoice_date'] !== ''
            ? Carbon::parse((string) $data['invoice_date'])->toDateString()
            : ($existing?->invoice_date?->toDateString() ?? now()->toDateString());

        $paymentTermsId = array_key_exists('payment_terms_id', $data)
            ? ($data['payment_terms_id'] !== null && $data['payment_terms_id'] !== '' ? (int) $data['payment_terms_id'] : null)
            : ($existing?->payment_terms_id !== null ? (int) $existing->payment_terms_id : $customer->payment_terms_id);

        $dueOn = isset($data['due_on']) && $data['due_on'] !== ''
            ? Carbon::parse((string) $data['due_on'])->toDateString()
            : $this->defaultDueOn($invoiceDate, $paymentTermsId);

        $currencyId = isset($data['currency_id']) && $data['currency_id'] !== null
            ? (int) $data['currency_id']
            : ($existing !== null ? (int) $existing->currency_id : (int) (Currency::getPrimary()?->id ?? 0));

        if ($currencyId <= 0) {
            abort(422, 'A currency is required.', ['X-Error-Code' => 'SALES_INVOICE_CURRENCY_REQUIRED']);
        }

        $exchangeRate = $this->resolveExchangeRate(
            $currencyId,
            $data['exchange_rate'] ?? $existing?->exchange_rate,
        );

        $salesmanId = array_key_exists('salesman_id', $data)
            ? ($data['salesman_id'] !== null && $data['salesman_id'] !== '' ? (string) $data['salesman_id'] : null)
            : ($existing?->salesman_id ?? $customer->salesman_id);

        $paymentMethodId = array_key_exists('payment_method_id', $data)
            ? ($data['payment_method_id'] !== null && $data['payment_method_id'] !== '' ? (int) $data['payment_method_id'] : null)
            : ($existing !== null ? $existing->payment_method_id : $customer->payment_method_id);

        $billingIncoming = $data['billing_address'] ?? null;
        if (isset($data['billing_address_id']) && $data['billing_address_id'] !== null && $data['billing_address_id'] !== '') {
            $billingIncoming = ['id' => (int) $data['billing_address_id']];
        }
        $shippingIncoming = $data['shipping_address'] ?? null;
        if (isset($data['shipping_address_id']) && $data['shipping_address_id'] !== null && $data['shipping_address_id'] !== '') {
            $shippingIncoming = ['id' => (int) $data['shipping_address_id']];
        }

        $billing = $this->resolveAddressSnapshot(
            $billingIncoming,
            $existing?->billing_address,
            $customer,
            'billing',
            $existing === null || (array_key_exists('customer_id', $data) && (string) $data['customer_id'] !== (string) $existing->customer_id),
        );
        $shipping = $this->resolveAddressSnapshot(
            $shippingIncoming,
            $existing?->shipping_address,
            $customer,
            'shipping',
            $existing === null || (array_key_exists('customer_id', $data) && (string) $data['customer_id'] !== (string) $existing->customer_id),
        );

        $adjustment = array_key_exists('adjustment', $data)
            ? PriceMath::normalize($data['adjustment'] ?? 0)
            : ($existing !== null ? (string) $existing->adjustment : PriceMath::normalize(0));

        return [
            'customer_id' => (string) $customer->id,
            'warehouse_id' => (int) ($data['warehouse_id'] ?? $existing?->warehouse_id),
            'currency_id' => $currencyId,
            'salesman_id' => $salesmanId,
            'payment_method_id' => $paymentMethodId,
            'payment_terms_id' => $paymentTermsId,
            'invoice_date' => $invoiceDate,
            'due_on' => $dueOn,
            'exchange_rate' => $exchangeRate,
            'reference_2' => $this->nullableString($data['reference_2'] ?? $existing?->reference_2),
            'billing_address' => $billing,
            'shipping_address' => $shipping,
            'adjustment' => $adjustment,
            'notes' => $this->nullableString($data['notes'] ?? $existing?->notes),
        ];
    }

    private function defaultDueOn(string $invoiceDate, ?int $paymentTermsId): string
    {
        $days = 0;
        if ($paymentTermsId !== null) {
            $term = PaymentTerm::query()->find($paymentTermsId);
            $days = (int) ($term?->due_days ?? 0);
        }

        return Carbon::parse($invoiceDate)->addDays($days)->toDateString();
    }

    private function resolveExchangeRate(int $currencyId, mixed $provided): string
    {
        $primary = Currency::getPrimary();
        if ($primary === null) {
            abort(422, 'A primary currency is required.', ['X-Error-Code' => 'SALES_INVOICE_PRIMARY_CURRENCY_REQUIRED']);
        }

        if ($currencyId === (int) $primary->id) {
            return number_format(1, 6, '.', '');
        }

        if ($provided !== null && $provided !== '') {
            $rate = (float) $provided;
            if ($rate <= 0) {
                abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => 'SALES_INVOICE_EXCHANGE_RATE_INVALID']);
            }

            return number_format($rate, 6, '.', '');
        }

        try {
            $rate = $this->exchangeRateService->getRateById($currencyId, (int) $primary->id);
        } catch (\InvalidArgumentException $e) {
            abort(422, 'Enter an exchange rate for this currency.', ['X-Error-Code' => 'SALES_INVOICE_EXCHANGE_RATE_REQUIRED']);
        }

        if ($rate <= 0) {
            abort(422, 'Exchange rate must be greater than zero.', ['X-Error-Code' => 'SALES_INVOICE_EXCHANGE_RATE_INVALID']);
        }

        return number_format($rate, 6, '.', '');
    }

    /**
     * @param  array<string, mixed>|null  $incoming
     * @param  array<string, mixed>|null  $existing
     * @return array<string, mixed>|null
     */
    private function resolveAddressSnapshot(
        mixed $incoming,
        mixed $existing,
        Customer $customer,
        string $type,
        bool $prefillFromCustomer,
    ): ?array {
        if (is_array($incoming) && $incoming !== []) {
            $line1 = trim((string) ($incoming['address_line_1'] ?? ''));
            $line2 = trim((string) ($incoming['address_line_2'] ?? ''));
            $phone = trim((string) ($incoming['phone'] ?? ''));
            $hasManual = $line1 !== '' || $line2 !== '' || $phone !== '';

            if ($hasManual) {
                return $this->normalizeAddressArray($incoming, $type);
            }

            $id = isset($incoming['id']) ? (int) $incoming['id'] : 0;
            if ($id > 0) {
                $row = $customer->addresses()
                    ->whereKey($id)
                    ->where('address_type', $type)
                    ->first();
                if ($row instanceof CustomerAddress) {
                    return $this->snapshotAddress($row);
                }
            }
        }

        if (! $prefillFromCustomer && is_array($existing)) {
            return $existing;
        }

        $row = $customer->addresses()
            ->where('address_type', $type)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        return $row instanceof CustomerAddress ? $this->snapshotAddress($row) : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeAddressArray(array $row, string $type): array
    {
        return [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'address_type' => (string) ($row['address_type'] ?? $type),
            'address_line_1' => (string) ($row['address_line_1'] ?? ''),
            'address_line_2' => isset($row['address_line_2']) ? (string) $row['address_line_2'] : null,
            'city' => (string) ($row['city'] ?? ''),
            'state' => (string) ($row['state'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'phone' => isset($row['phone']) && $row['phone'] !== '' ? (string) $row['phone'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotAddress(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'address_type' => (string) $address->address_type,
            'address_line_1' => $address->address_line_1,
            'address_line_2' => $address->address_line_2,
            'city' => $address->city,
            'state' => $address->state,
            'country' => $address->country,
            'phone' => $address->phone,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesInvoice $invoice, array $lines): void
    {
        $invoice->loadMissing('customer');
        $customer = $invoice->customer;
        $date = $invoice->invoice_date->toDateString();
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $headerWarehouseId = (int) $invoice->warehouse_id;

        SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->delete();

        foreach (array_values($lines) as $index => $row) {
            $item = Item::query()->findOrFail((string) $row['item_id']);
            SalesInvoiceRules::assertSellableItem($item);

            $qty = SalesInvoiceLineQuantity::resolve(
                $item,
                (float) $row['quantity'],
                isset($row['item_uom_id']) && $row['item_uom_id'] !== null && $row['item_uom_id'] !== ''
                    ? (int) $row['item_uom_id']
                    : null,
            );

            $trackInventory = (bool) $item->track_inventory;
            $warehouseId = isset($row['warehouse_id']) && $row['warehouse_id'] !== null && $row['warehouse_id'] !== ''
                ? (int) $row['warehouse_id']
                : ($trackInventory ? $headerWarehouseId : null);

            if ($trackInventory) {
                if ($warehouseId === null) {
                    abort(422, 'Warehouse is required for stock items.', ['X-Error-Code' => 'SALES_INVOICE_LINE_WAREHOUSE_REQUIRED']);
                }
                SalesInvoiceRules::assertWarehouse($warehouseId);
            } elseif ($warehouseId !== null) {
                SalesInvoiceRules::assertWarehouse($warehouseId);
            }

            $lotId = isset($row['lot_id']) && $row['lot_id'] !== null && $row['lot_id'] !== ''
                ? (int) $row['lot_id']
                : null;
            if ($item->track_lots) {
                if ($lotId === null) {
                    abort(422, 'Select a lot for this item.', ['X-Error-Code' => 'STOCK_LOT_REQUIRED']);
                }
                $lot = InventoryLot::query()->findOrFail($lotId);
                if ((string) $lot->item_id !== (string) $item->id) {
                    abort(422, 'Lot does not belong to this item.', ['X-Error-Code' => 'SALES_INVOICE_LOT_ITEM_MISMATCH']);
                }
            } else {
                $lotId = null;
            }

            $unitPrice = PriceMath::normalize($row['unit_price'] ?? 0);
            $discountPercent = (string) ($row['discount_percent'] ?? 0);
            $taxRate = DocumentTaxContext::ratePercentForItem($item, $customer, $date);
            $math = DocumentTaxMath::line(
                $qty['quantity'],
                $unitPrice,
                $discountPercent,
                $taxRate,
                $includeTax,
            );

            SalesInvoiceLine::query()->create([
                'sales_invoice_id' => $invoice->id,
                'item_id' => $item->id,
                'item_uom_id' => $qty['item_uom_id'],
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'sort_order' => $index + 1,
                'quantity' => $qty['quantity'],
                'base_quantity' => $qty['base_quantity'],
                'conversion_factor' => $qty['conversion_factor'],
                'unit_price' => $unitPrice,
                'discount_percent' => PriceMath::normalize($discountPercent),
                'discount_amount' => $math['discount_amount'],
                'tax_rate' => $taxRate,
                'line_subtotal' => $math['line_subtotal'],
                'tax_amount' => $math['tax_amount'],
                'line_total' => $math['line_total'],
                'description' => $this->nullableString($row['description'] ?? $item->description ?? $item->name),
                'notes' => $this->nullableString($row['notes'] ?? null),
            ]);
        }

        $this->recalculateTotals($invoice);
    }

    private function recalculateTotals(SalesInvoice $invoice): void
    {
        $includeTax = DocumentTaxContext::pricesIncludeTax();
        $rows = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $linePayload = [];
        foreach ($rows as $line) {
            $merchandise = bcmul((string) $line->quantity, (string) $line->unit_price, 8);
            $linePayload[] = [
                'merchandise' => $merchandise,
                'discount_amount' => (string) $line->discount_amount,
                'tax_amount' => (string) $line->tax_amount,
            ];
        }

        $totals = DocumentTaxMath::sumTotals(
            $linePayload,
            $invoice->adjustment,
            $includeTax,
        );

        $invoice->update([
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
            'paid_total' => PriceMath::normalize($invoice->paid_total ?? 0),
            'net_to_pay' => $totals['net_to_pay'],
        ]);
    }

    private function postLineStock(SalesInvoice $invoice, SalesInvoiceLine $line, ?string $userId): void
    {
        $item = $line->item ?? Item::query()->findOrFail($line->item_id);

        if (SalesInvoiceRules::isBundleItem($item)) {
            $this->postBundleComponents($invoice, $line, $item, $userId);

            return;
        }

        if (! $item->track_inventory) {
            return;
        }

        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = 'SI '.$invoice->invoice_number;
        $outbound = bcmul((string) $line->base_quantity, '-1', 6);

        $this->stockMovementService->post(StockMovementData::forSale(
            itemId: (string) $line->item_id,
            warehouseId: $warehouseId,
            quantityDelta: $outbound,
            salesInvoiceId: (string) $invoice->id,
            itemUomId: $line->item_uom_id ? (int) $line->item_uom_id : null,
            notes: $line->notes ? $note.' — '.$line->notes : $note,
            userId: $userId,
            lotId: $line->lot_id ? (int) $line->lot_id : null,
        ));
    }

    private function postBundleComponents(
        SalesInvoice $invoice,
        SalesInvoiceLine $line,
        Item $bundle,
        ?string $userId,
    ): void {
        $components = BundleItem::query()
            ->where('bundle_item_id', $bundle->id)
            ->with('childItem.itemType')
            ->get();

        if ($components->isEmpty()) {
            abort(422, 'Bundle has no components.', ['X-Error-Code' => 'SALES_INVOICE_BUNDLE_EMPTY']);
        }

        $warehouseId = (int) ($line->warehouse_id ?? $invoice->warehouse_id);
        $note = 'SI '.$invoice->invoice_number.' bundle '.$bundle->item_code;

        foreach ($components as $component) {
            $child = $component->childItem;
            if (! $child instanceof Item || ! $child->track_inventory) {
                continue;
            }
            if ($child->track_lots) {
                abort(422, 'Lot-tracked bundle components cannot be sold on an invoice. Explode the bundle first.', [
                    'X-Error-Code' => 'SALES_INVOICE_BUNDLE_LOT_NOT_SUPPORTED',
                ]);
            }

            $baseQty = bcmul((string) $line->base_quantity, (string) $component->quantity, 6);
            $outbound = bcmul($baseQty, '-1', 6);

            $this->stockMovementService->post(StockMovementData::forSaleBundleComponent(
                itemId: (string) $child->id,
                warehouseId: $warehouseId,
                quantityDelta: $outbound,
                salesInvoiceId: (string) $invoice->id,
                notes: $note,
                userId: $userId,
            ));
        }
    }

    private function lockDraft(SalesInvoice $invoice): SalesInvoice
    {
        $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        SalesInvoiceRules::assertDraft($locked);
        $this->warehouseService->assertVisibleById((int) $locked->warehouse_id);

        return $locked;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
