<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Inventory\Stock\Services\InventorySerialService;
use App\Modules\PaymentTerm\Models\PaymentTerm;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNoteLine;
use App\Modules\Sales\DeliveryNote\Services\DeliveryNoteInvoicing;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceLineQuantity;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Sales\Support\CommercialLineBuilder;
use App\Modules\Sales\Support\DocumentTaxMode;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\DocumentTaxContext;
use App\Support\DocumentTaxMath;
use App\Support\ExchangeRateSnapshot;
use App\Support\PaymentAllocation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Price and tax: the draft header and lines (customer, addresses, rates, discounts, totals).
 * Part of the sales invoice posting pipeline (see SalesInvoiceService).
 */
class SalesInvoiceDraftService
{
    public function __construct(
        private readonly SalesInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

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
                'sales_order_id' => isset($data['sales_order_id']) && $data['sales_order_id'] !== ''
                    ? (string) $data['sales_order_id']
                    : null,
                'status' => SalesInvoiceStatus::Draft,
                'paid_total' => PriceMath::normalize(0),
                'created_by' => $userId,
            ]);

            DocumentTaxMode::stamp($invoice, isset($data['prices_include_tax']) ? (bool) $data['prices_include_tax'] : null, (string) $invoice->customer_id, $invoice->branch_id !== null ? (int) $invoice->branch_id : null, $invoice->invoice_date->toDateString(), (int) $invoice->currency_id);

            if (! empty($data['lines'])) {
                $this->replaceLines($invoice, $data['lines']);
            } else {
                $this->recalculateTotals($invoice);
            }

            return $this->queryService->find($invoice->id);
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
                'header_discount_type',
                'header_discount_value',
                'notes',
            ]), $data);

            $header = $this->normalizeHeader($merged, $customer, $invoice);
            $invoice->update($header);
            $this->recalculateTotals($invoice->fresh() ?? $invoice);

            return $this->queryService->find($invoice->id);
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
        $this->warehouseService->assertDocumentVisible($invoice);
        $invoice->delete();
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

        $exchangeRate = ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            $currencyId,
            $data['exchange_rate']
                ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
            'SALES_INVOICE',
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
            ...CommercialLineBuilder::headerDiscount($data, $existing),
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
        $includeTax = DocumentTaxMode::of($invoice);
        $headerWarehouseId = (int) $invoice->warehouse_id;

        SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->delete();

        foreach (array_values($lines) as $index => $row) {
            $item = Item::query()->findOrFail((string) $row['item_id']);
            SalesInvoiceRules::assertSellableItem($item);

            $qty = SalesInvoiceLineQuantity::resolve(
                $item,
                $row['quantity'],
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

            $deliveryLineId = isset($row['delivery_note_line_id']) && $row['delivery_note_line_id'] !== null && $row['delivery_note_line_id'] !== ''
                ? (int) $row['delivery_note_line_id']
                : null;
            if ($deliveryLineId !== null) {
                app(DeliveryNoteInvoicing::class)->assertLinkable($deliveryLineId, (string) $invoice->customer_id, (string) $item->id, $qty['item_uom_id']);
            }

            // Units already left on the delivery note: the invoice just shows its serials.
            $serialNumbers = $deliveryLineId !== null
                ? ($item->track_serials ? (DeliveryNoteLine::query()->find($deliveryLineId)?->serial_numbers ?? []) : null)
                : app(InventorySerialService::class)->forLine($item, $row['serial_numbers'] ?? null, $qty['base_quantity'], $index + 1);

            SalesInvoiceLine::query()->create([
                'sales_invoice_id' => $invoice->id,
                'sales_order_line_id' => isset($row['sales_order_line_id']) && $row['sales_order_line_id'] !== null && $row['sales_order_line_id'] !== ''
                    ? (int) $row['sales_order_line_id']
                    : null,
                'delivery_note_line_id' => $deliveryLineId,
                'item_id' => $item->id,
                'item_uom_id' => $qty['item_uom_id'],
                'warehouse_id' => $warehouseId,
                'lot_id' => $lotId,
                'serial_numbers' => $serialNumbers,
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

    public function recalculateTotals(SalesInvoice $invoice): void
    {
        $includeTax = DocumentTaxMode::of($invoice);
        $rows = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        CommercialLineBuilder::applyHeaderDiscount($rows, $invoice->header_discount_type, $invoice->header_discount_value, $includeTax);

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
            'net_to_pay' => PaymentAllocation::netToPay($totals['grand_total'], $invoice->paid_total ?? 0, $invoice->credited_total ?? 0),
        ]);
    }

    public function lockDraft(SalesInvoice $invoice): SalesInvoice
    {
        $locked = SalesInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
        SalesInvoiceRules::assertDraft($locked);
        $this->warehouseService->assertDocumentVisible($locked);

        return $locked;
    }

    public function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
