<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierPayment\Services;

use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Purchasing\Enums\PurchaseInvoiceStatus;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\SupplierPayment\DTOs\SupplierPaymentResponseData;
use App\Modules\Inventory\Purchasing\SupplierPayment\Enums\SupplierPaymentStatus;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPayment;
use App\Modules\Inventory\Purchasing\SupplierPayment\Models\SupplierPaymentAllocation;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use App\Support\PaymentAllocation;
use App\Support\SequentialCodeGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupplierPaymentService
{
    public function __construct(
        private readonly SupplierLedgerService $supplierLedgerService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly WarehouseService $warehouseService,
    ) {}

    /**
     * @param  array{
     *   status?:string|null,
     *   supplier_id?:string|null,
     *   search?:string|null,
     *   from?:string|null,
     *   to?:string|null
     * }  $filters
     * @return LengthAwarePaginator<int, SupplierPayment>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = SupplierPayment::query()
            ->with([
                'supplier:id,supplier_code,name,phone,is_active',
                'currency:id,code,name,symbol',
                'paymentMethod:id,name,code,type,requires_reference,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('allocations');

        if (! empty($filters['status'])) {
            $status = SupplierPaymentStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (string) $filters['supplier_id']);
        }

        ListPagination::applySearch($query, $filters['search'] ?? null, ['payment_number']);

        if (! empty($filters['from'])) {
            $query->where('payment_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('payment_date', '<=', $filters['to']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function find(string $id): SupplierPayment
    {
        return SupplierPayment::query()
            ->with([
                'supplier',
                'currency',
                'paymentMethod',
                'createdByUser',
                'postedByUser',
                'allocations.purchaseInvoice',
            ])
            ->withCount('allocations')
            ->findOrFail($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openInvoices(string $supplierId, int $currencyId): array
    {
        if ($supplierId === '' || $currencyId <= 0) {
            $this->fail('Supplier and currency are required.', 'SUPPLIER_PAYMENT_FILTER_REQUIRED');
        }

        $query = PurchaseInvoice::query()
            ->with('currency:id,code,name,symbol')
            ->where('supplier_id', $supplierId)
            ->where('status', PurchaseInvoiceStatus::Posted->value)
            ->where('net_to_pay', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('invoice_number');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        return $query->get()
            ->map(fn (PurchaseInvoice $invoice): array => SupplierPaymentResponseData::openInvoice($invoice))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SupplierPayment
    {
        $supplier = PurchaseInvoiceRules::assertSupplier((string) $data['supplier_id']);
        $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

        return DB::transaction(function () use ($data, $userId, $supplier): SupplierPayment {
            $payment = SupplierPayment::query()->create([
                ...$this->headerAttributes($data, null),
                'supplier_id' => $supplier->id,
                'payment_number' => SequentialCodeGenerator::next(SupplierPayment::class, 'payment_number', 'SP-'),
                'status' => SupplierPaymentStatus::Draft,
                'created_by' => $userId,
            ]);

            $this->replaceAllocations($payment, $data['allocations'] ?? []);

            return $this->find($payment->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SupplierPayment $payment, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $data): SupplierPayment {
            $locked = $this->lockDraft($payment);
            $supplier = PurchaseInvoiceRules::assertSupplier((string) $data['supplier_id']);
            $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

            $partyChanged = (string) $supplier->id !== (string) $locked->supplier_id
                || (int) $data['currency_id'] !== (int) $locked->currency_id;
            if ($partyChanged && $locked->allocations()->exists()) {
                $this->fail(
                    'Remove allocations before changing the supplier or currency.',
                    'SUPPLIER_PAYMENT_HEADER_LOCKED',
                );
            }

            $locked->update([
                ...$this->headerAttributes($data, $locked),
                'supplier_id' => $supplier->id,
            ]);
            $locked->refresh();
            $this->refreshAppliedAmounts($locked);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     */
    public function syncAllocations(SupplierPayment $payment, array $allocations): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $allocations): SupplierPayment {
            $locked = $this->lockDraft($payment);
            $this->replaceAllocations($locked, $allocations);

            return $this->find($locked->id);
        });
    }

    public function delete(SupplierPayment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = $this->lockDraft($payment);
            $locked->delete();
        });
    }

    public function post(SupplierPayment $payment, ?string $userId): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $userId): SupplierPayment {
            $locked = SupplierPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SupplierPaymentStatus::Draft) {
                $this->fail('Only a draft supplier payment can be posted.', 'SUPPLIER_PAYMENT_NOT_DRAFT');
            }

            if (! PaymentAllocation::isPositive($locked->amount)) {
                $this->fail('Payment amount must be greater than zero.', 'SUPPLIER_PAYMENT_AMOUNT_INVALID');
            }

            $this->refreshAppliedAmounts($locked);

            $allocations = SupplierPaymentAllocation::query()
                ->where('supplier_payment_id', $locked->id)
                ->orderBy('id')
                ->get();

            if ($allocations->isEmpty() || ! PaymentAllocation::same($locked->amount, PaymentAllocation::sum($allocations->pluck('amount')->all()))) {
                $this->fail('Allocations must add up to the payment amount.', 'SUPPLIER_PAYMENT_ALLOCATION_MISMATCH');
            }

            $this->assertPaymentMethod((int) $locked->payment_method_id, $locked->reference);
            $supplier = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);

            $invoiceIds = $allocations->pluck('purchase_invoice_id')->map(fn ($id): string => (string) $id)->all();
            $invoices = PurchaseInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($invoices->count() !== count($invoiceIds)) {
                $this->fail('An allocated invoice does not match this payment.', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');
            }

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation->purchase_invoice_id);
                if (! $invoice instanceof PurchaseInvoice) {
                    $this->fail('An allocated invoice does not match this payment.', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');
                }

                $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
                $this->assertInvoiceOpenFor($locked, $invoice, (string) $allocation->applied_amount);

                $paid = PaymentAllocation::add($invoice->paid_total, $allocation->applied_amount);
                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid),
                ]);
            }

            foreach ($this->appliedByCurrency($allocations, $invoices) as $currencyId => $applied) {
                $this->supplierLedgerService->postEntry(
                    $supplier,
                    $currencyId,
                    $applied,
                    '0',
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    $locked->payment_date->toDateString(),
                );
            }

            $locked->update([
                'status' => SupplierPaymentStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reverse(SupplierPayment $payment): SupplierPayment
    {
        return DB::transaction(function () use ($payment): SupplierPayment {
            $locked = SupplierPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SupplierPaymentStatus::Posted) {
                $this->fail('Only a posted supplier payment can be reversed.', 'SUPPLIER_PAYMENT_NOT_POSTED');
            }

            $allocations = SupplierPaymentAllocation::query()
                ->where('supplier_payment_id', $locked->id)
                ->orderBy('id')
                ->get();

            $invoiceIds = $allocations->pluck('purchase_invoice_id')->map(fn ($id): string => (string) $id)->all();
            $invoices = PurchaseInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation->purchase_invoice_id);
                if (! $invoice instanceof PurchaseInvoice || $invoice->status !== PurchaseInvoiceStatus::Posted) {
                    $this->fail('An allocated invoice is no longer open.', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');
                }

                $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
                $paid = PaymentAllocation::subtract($invoice->paid_total, $allocation->applied_amount);
                if ($paid === null) {
                    $this->fail(
                        'This payment cannot be reversed because an invoice paid total is lower than the allocation.',
                        'SUPPLIER_PAYMENT_PAID_TOTAL_UNDERFLOW',
                    );
                }

                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid),
                ]);
            }

            $supplier = Supplier::query()->findOrFail($locked->supplier_id);
            foreach ($this->appliedByCurrency($allocations, $invoices) as $currencyId => $applied) {
                $this->supplierLedgerService->postEntry(
                    $supplier,
                    $currencyId,
                    '0',
                    $applied,
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }

            $locked->update(['status' => SupplierPaymentStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data, ?SupplierPayment $existing): array
    {
        $currencyId = (int) $data['currency_id'];

        return [
            'currency_id' => $currencyId,
            'exchange_rate' => ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                $currencyId,
                $data['exchange_rate']
                    ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
                'SUPPLIER_PAYMENT',
            ),
            'payment_method_id' => (int) $data['payment_method_id'],
            'payment_date' => Carbon::parse((string) $data['payment_date'])->toDateString(),
            'amount' => PaymentAllocation::normalize($data['amount']),
            'reference' => $this->nullableString($data['reference'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceAllocations(SupplierPayment $payment, array $rows): void
    {
        $seen = [];
        $payload = [];
        foreach ($rows as $row) {
            $invoiceId = (string) ($row['purchase_invoice_id'] ?? '');
            if ($invoiceId === '' || isset($seen[$invoiceId])) {
                $this->fail('Each invoice can be allocated only once on a payment.', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');
            }
            $seen[$invoiceId] = true;
            $amount = PaymentAllocation::normalize($row['amount'] ?? 0);
            if (! PaymentAllocation::isPositive($amount)) {
                $this->fail('Each allocation must be greater than zero.', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');
            }
            $payload[] = ['purchase_invoice_id' => $invoiceId, 'amount' => $amount];
        }

        $invoices = PurchaseInvoice::query()
            ->whereIn('id', array_keys($seen))
            ->get()
            ->keyBy('id');

        foreach ($payload as $index => $row) {
            $invoice = $invoices->get($row['purchase_invoice_id']);
            if (! $invoice instanceof PurchaseInvoice) {
                $this->fail('An allocated invoice does not match this payment.', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');
            }
            $this->assertInvoiceBelongs($payment, $invoice);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $payload[$index] += $this->appliedSettlement($payment, $invoice, $row['amount']);
        }

        $payment->allocations()->delete();
        foreach ($payload as $row) {
            SupplierPaymentAllocation::query()->create([
                'supplier_payment_id' => $payment->id,
                'purchase_invoice_id' => $row['purchase_invoice_id'],
                'amount' => $row['amount'],
                'applied_amount' => $row['applied_amount'],
                'applied_exchange_rate' => $row['applied_exchange_rate'],
            ]);
        }
        $payment->touch();
    }

    private function refreshAppliedAmounts(SupplierPayment $payment): void
    {
        $allocations = SupplierPaymentAllocation::query()
            ->where('supplier_payment_id', $payment->id)
            ->with('purchaseInvoice')
            ->get();

        foreach ($allocations as $allocation) {
            $invoice = $allocation->purchaseInvoice;
            if (! $invoice instanceof PurchaseInvoice) {
                $this->fail('An allocated invoice does not match this payment.', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');
            }

            $allocation->update($this->appliedSettlement($payment, $invoice, (string) $allocation->amount));
        }
    }

    /**
     * @return array{applied_amount: string, applied_exchange_rate: string}
     */
    private function appliedSettlement(SupplierPayment $payment, PurchaseInvoice $invoice, string $amount): array
    {
        $sameCurrency = (int) $invoice->currency_id === (int) $payment->currency_id;
        $invoiceRate = $sameCurrency
            ? (string) $payment->exchange_rate
            : ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                (int) $invoice->currency_id,
                null,
                'SUPPLIER_PAYMENT',
            );
        $applied = PaymentAllocation::toInvoiceCurrency($amount, (string) $payment->exchange_rate, $invoiceRate, $sameCurrency);
        if (! PaymentAllocation::isPositive($applied)) {
            $this->fail('Allocation does not settle any of the invoice balance.', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');
        }

        return [
            'applied_amount' => $applied,
            'applied_exchange_rate' => $invoiceRate,
        ];
    }

    /**
     * @param  Collection<int, SupplierPaymentAllocation>  $allocations
     * @param  Collection<string, PurchaseInvoice>  $invoices
     * @return array<int, string>
     */
    private function appliedByCurrency($allocations, $invoices): array
    {
        $totals = [];
        foreach ($allocations as $allocation) {
            $invoice = $invoices->get($allocation->purchase_invoice_id);
            if (! $invoice instanceof PurchaseInvoice) {
                continue;
            }
            $currencyId = (int) $invoice->currency_id;
            $totals[$currencyId] = PaymentAllocation::add($totals[$currencyId] ?? '0', $allocation->applied_amount);
        }

        return $totals;
    }

    private function assertInvoiceBelongs(SupplierPayment $payment, PurchaseInvoice $invoice): void
    {
        if ($invoice->status !== PurchaseInvoiceStatus::Posted) {
            $this->fail('Only a posted invoice can be allocated.', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');
        }

        if ((string) $invoice->supplier_id !== (string) $payment->supplier_id) {
            $this->fail('Invoice supplier does not match this payment.', 'SUPPLIER_PAYMENT_INVOICE_MISMATCH');
        }
    }

    private function assertInvoiceOpenFor(SupplierPayment $payment, PurchaseInvoice $invoice, string $amount): void
    {
        $this->assertInvoiceBelongs($payment, $invoice);

        if (! PaymentAllocation::isPositive($amount) || PaymentAllocation::exceeds($amount, $invoice->net_to_pay)) {
            $this->fail('Allocation exceeds the invoice open amount.', 'SUPPLIER_PAYMENT_INVOICE_NOT_OPEN');
        }
    }

    private function assertPaymentMethod(int $paymentMethodId, mixed $reference): void
    {
        $method = PaymentMethod::query()->find($paymentMethodId);
        if (! $method instanceof PaymentMethod || ! $method->is_active || $method->type === PaymentMethodType::Credit) {
            $this->fail('Choose an active payment method that is not account credit.', 'PAYMENT_METHOD_NOT_ALLOWED');
        }

        if ($method->requires_reference && $this->nullableString($reference) === null) {
            $this->fail('A reference is required for this payment method.', 'PAYMENT_REFERENCE_REQUIRED');
        }
    }

    private function lockDraft(SupplierPayment $payment): SupplierPayment
    {
        $locked = SupplierPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== SupplierPaymentStatus::Draft) {
            $this->fail('Only a draft supplier payment can be changed.', 'SUPPLIER_PAYMENT_NOT_DRAFT');
        }

        return $locked;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function fail(string $message, string $code): never
    {
        abort(422, $message, ['X-Error-Code' => $code]);
    }
}
