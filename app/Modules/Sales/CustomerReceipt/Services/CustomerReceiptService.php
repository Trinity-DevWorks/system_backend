<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerReceipt\Services;

use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\InvoiceProof\Services\InvoiceChainRegistrationService;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Sales\CustomerReceipt\DTOs\CustomerReceiptResponseData;
use App\Modules\Sales\CustomerReceipt\Enums\CustomerReceiptStatus;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceipt;
use App\Modules\Sales\CustomerReceipt\Models\CustomerReceiptAllocation;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Support\SalesInvoiceRules;
use App\Modules\Warehouse\Services\WarehouseService;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use App\Support\PaymentAllocation;
use App\Support\SequentialCodeGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerReceiptService
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly WarehouseService $warehouseService,
        private readonly InvoiceChainRegistrationService $invoiceChainRegistrationService,
    ) {}

    /**
     * @param  array{
     *   status?:string|null,
     *   customer_id?:string|null,
     *   search?:string|null,
     *   from?:string|null,
     *   to?:string|null
     * }  $filters
     * @return LengthAwarePaginator<int, CustomerReceipt>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = CustomerReceipt::query()
            ->with([
                'customer:id,customer_code,name,phone,status,is_system',
                'currency:id,code,name,symbol',
                'paymentMethod:id,name,code,type,requires_reference,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('allocations');

        if (! empty($filters['status'])) {
            $status = CustomerReceiptStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (string) $filters['customer_id']);
        }

        ListPagination::applySearch($query, $filters['search'] ?? null, ['receipt_number']);

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

    public function find(string $id): CustomerReceipt
    {
        return CustomerReceipt::query()
            ->with([
                'customer',
                'currency',
                'paymentMethod',
                'createdByUser',
                'postedByUser',
                'allocations.salesInvoice',
            ])
            ->withCount('allocations')
            ->findOrFail($id);
    }

    /**
     * Posted sales invoices for this customer that still have an open balance, in any currency.
     *
     * @return list<array<string, mixed>>
     */
    public function openInvoices(string $customerId, int $currencyId): array
    {
        if ($customerId === '' || $currencyId <= 0) {
            $this->fail('Customer and currency are required.', 'CUSTOMER_RECEIPT_FILTER_REQUIRED');
        }

        $query = SalesInvoice::query()
            ->with('currency:id,code,name,symbol')
            ->where('customer_id', $customerId)
            ->where('status', SalesInvoiceStatus::Posted->value)
            ->where('net_to_pay', '>', 0)
            ->orderBy('invoice_date')
            ->orderBy('invoice_number');

        $this->warehouseService->applyVisibleWarehouseConstraint($query, 'warehouse_id');

        return $query->get()
            ->map(fn (SalesInvoice $invoice): array => CustomerReceiptResponseData::openInvoice($invoice))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): CustomerReceipt
    {
        $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
        $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

        return DB::transaction(function () use ($data, $userId, $customer): CustomerReceipt {
            $receipt = CustomerReceipt::query()->create([
                ...$this->headerAttributes($data, null),
                'customer_id' => $customer->id,
                'receipt_number' => SequentialCodeGenerator::next(CustomerReceipt::class, 'receipt_number', 'CR-'),
                'status' => CustomerReceiptStatus::Draft,
                'created_by' => $userId,
            ]);

            $this->replaceAllocations($receipt, $data['allocations'] ?? []);

            return $this->find($receipt->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(CustomerReceipt $receipt, array $data): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $data): CustomerReceipt {
            $locked = $this->lockDraft($receipt);
            $customer = SalesInvoiceRules::assertCustomer((string) $data['customer_id']);
            $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

            $partyChanged = (string) $customer->id !== (string) $locked->customer_id
                || (int) $data['currency_id'] !== (int) $locked->currency_id;
            if ($partyChanged && $locked->allocations()->exists()) {
                $this->fail(
                    'Remove allocations before changing the customer or currency.',
                    'CUSTOMER_RECEIPT_HEADER_LOCKED',
                );
            }

            $locked->update([
                ...$this->headerAttributes($data, $locked),
                'customer_id' => $customer->id,
            ]);
            $locked->refresh();
            $this->refreshAppliedAmounts($locked);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     */
    public function syncAllocations(CustomerReceipt $receipt, array $allocations): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $allocations): CustomerReceipt {
            $locked = $this->lockDraft($receipt);
            $this->replaceAllocations($locked, $allocations);

            return $this->find($locked->id);
        });
    }

    public function delete(CustomerReceipt $receipt): void
    {
        DB::transaction(function () use ($receipt): void {
            $locked = $this->lockDraft($receipt);
            $locked->delete();
        });
    }

    public function post(CustomerReceipt $receipt, ?string $userId): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $userId): CustomerReceipt {
            $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CustomerReceiptStatus::Draft) {
                $this->fail('Only a draft customer receipt can be posted.', 'CUSTOMER_RECEIPT_NOT_DRAFT');
            }

            if (! PaymentAllocation::isPositive($locked->amount)) {
                $this->fail('Receipt amount must be greater than zero.', 'CUSTOMER_RECEIPT_AMOUNT_INVALID');
            }

            $this->refreshAppliedAmounts($locked);

            $allocations = CustomerReceiptAllocation::query()
                ->where('customer_receipt_id', $locked->id)
                ->orderBy('id')
                ->get();

            if ($allocations->isEmpty() || ! PaymentAllocation::same($locked->amount, PaymentAllocation::sum($allocations->pluck('amount')->all()))) {
                $this->fail('Allocations must add up to the receipt amount.', 'CUSTOMER_RECEIPT_ALLOCATION_MISMATCH');
            }

            $this->assertPaymentMethod((int) $locked->payment_method_id, $locked->reference);
            $customer = SalesInvoiceRules::assertCustomer((string) $locked->customer_id);

            $invoiceIds = $allocations->pluck('sales_invoice_id')->map(fn ($id): string => (string) $id)->all();
            $invoices = SalesInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($invoices->count() !== count($invoiceIds)) {
                $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation->sales_invoice_id);
                if (! $invoice instanceof SalesInvoice) {
                    $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
                }

                $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
                $this->assertInvoiceOpenFor($locked, $invoice, (string) $allocation->applied_amount);

                $paid = PaymentAllocation::add($invoice->paid_total, $allocation->applied_amount);
                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
                ]);
            }

            foreach ($this->appliedByCurrency($allocations, $invoices) as $currencyId => $applied) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    $currencyId,
                    '0',
                    $applied,
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    $locked->payment_date->toDateString(),
                );
            }

            $locked->update([
                'status' => CustomerReceiptStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reverse(CustomerReceipt $receipt): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt): CustomerReceipt {
            $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== CustomerReceiptStatus::Posted) {
                $this->fail('Only a posted customer receipt can be reversed.', 'CUSTOMER_RECEIPT_NOT_POSTED');
            }

            $allocations = CustomerReceiptAllocation::query()
                ->where('customer_receipt_id', $locked->id)
                ->orderBy('id')
                ->get();

            $invoiceIds = $allocations->pluck('sales_invoice_id')->map(fn ($id): string => (string) $id)->all();
            $invoices = SalesInvoice::query()
                ->whereIn('id', $invoiceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($allocations as $allocation) {
                $invoice = $invoices->get($allocation->sales_invoice_id);
                if (! $invoice instanceof SalesInvoice || $invoice->status !== SalesInvoiceStatus::Posted) {
                    $this->fail('An allocated invoice is no longer open.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
                }

                $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
                $paid = PaymentAllocation::subtract($invoice->paid_total, $allocation->applied_amount);
                if ($paid === null) {
                    $this->fail(
                        'This receipt cannot be reversed because an invoice paid total is lower than the allocation.',
                        'CUSTOMER_RECEIPT_PAID_TOTAL_UNDERFLOW',
                    );
                }

                $invoice->update([
                    'paid_total' => $paid,
                    'net_to_pay' => PaymentAllocation::netToPay($invoice->grand_total, $paid, $invoice->credited_total),
                ]);
            }

            $customer = Customer::query()->findOrFail($locked->customer_id);
            foreach ($this->appliedByCurrency($allocations, $invoices) as $currencyId => $applied) {
                $this->customerLedgerService->postEntry(
                    $customer,
                    $currencyId,
                    $applied,
                    '0',
                    LedgerReferenceType::Payment,
                    (string) $locked->id,
                    now()->toDateString(),
                );
            }

            $locked->update(['status' => CustomerReceiptStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data, ?CustomerReceipt $existing): array
    {
        $currencyId = (int) $data['currency_id'];

        return [
            'currency_id' => $currencyId,
            'exchange_rate' => ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                $currencyId,
                $data['exchange_rate']
                    ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
                'CUSTOMER_RECEIPT',
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
    private function replaceAllocations(CustomerReceipt $receipt, array $rows): void
    {
        $seen = [];
        $payload = [];
        foreach ($rows as $row) {
            $invoiceId = (string) ($row['sales_invoice_id'] ?? '');
            if ($invoiceId === '' || isset($seen[$invoiceId])) {
                $this->fail('Each invoice can be allocated only once on a receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }
            $seen[$invoiceId] = true;
            $amount = PaymentAllocation::normalize($row['amount'] ?? 0);
            if (! PaymentAllocation::isPositive($amount)) {
                $this->fail('Each allocation must be greater than zero.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
            }
            $payload[] = ['sales_invoice_id' => $invoiceId, 'amount' => $amount];
        }

        $invoices = SalesInvoice::query()
            ->whereIn('id', array_keys($seen))
            ->get()
            ->keyBy('id');

        foreach ($payload as $index => $row) {
            $invoice = $invoices->get($row['sales_invoice_id']);
            if (! $invoice instanceof SalesInvoice) {
                $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }
            $this->assertInvoiceBelongs($receipt, $invoice);
            $this->warehouseService->assertVisibleById((int) $invoice->warehouse_id);
            $payload[$index] += $this->appliedSettlement($receipt, $invoice, $row['amount']);
        }

        $receipt->allocations()->delete();
        foreach ($payload as $row) {
            CustomerReceiptAllocation::query()->create([
                'customer_receipt_id' => $receipt->id,
                'sales_invoice_id' => $row['sales_invoice_id'],
                'amount' => $row['amount'],
                'applied_amount' => $row['applied_amount'],
                'applied_exchange_rate' => $row['applied_exchange_rate'],
            ]);
        }
        $receipt->touch();
    }

    private function refreshAppliedAmounts(CustomerReceipt $receipt): void
    {
        $allocations = CustomerReceiptAllocation::query()
            ->where('customer_receipt_id', $receipt->id)
            ->with('salesInvoice')
            ->get();

        foreach ($allocations as $allocation) {
            $invoice = $allocation->salesInvoice;
            if (! $invoice instanceof SalesInvoice) {
                $this->fail('An allocated invoice does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
            }

            $settlement = $this->appliedSettlement($receipt, $invoice, (string) $allocation->amount);
            $allocation->update($settlement);
        }
    }

    /**
     * @return array{applied_amount: string, applied_exchange_rate: string}
     */
    private function appliedSettlement(CustomerReceipt $receipt, SalesInvoice $invoice, string $amount): array
    {
        $sameCurrency = (int) $invoice->currency_id === (int) $receipt->currency_id;
        $invoiceRate = $sameCurrency
            ? (string) $receipt->exchange_rate
            : ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                (int) $invoice->currency_id,
                null,
                'CUSTOMER_RECEIPT',
            );
        $applied = PaymentAllocation::toInvoiceCurrency($amount, (string) $receipt->exchange_rate, $invoiceRate, $sameCurrency);
        if (! PaymentAllocation::isPositive($applied)) {
            $this->fail('Allocation does not settle any of the invoice balance.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
        }

        return [
            'applied_amount' => $applied,
            'applied_exchange_rate' => $invoiceRate,
        ];
    }

    /**
     * @param  Collection<int, CustomerReceiptAllocation>  $allocations
     * @param  Collection<string, SalesInvoice>  $invoices
     * @return array<int, string>
     */
    private function appliedByCurrency($allocations, $invoices): array
    {
        $totals = [];
        foreach ($allocations as $allocation) {
            $invoice = $invoices->get($allocation->sales_invoice_id);
            if (! $invoice instanceof SalesInvoice) {
                continue;
            }
            $currencyId = (int) $invoice->currency_id;
            $totals[$currencyId] = PaymentAllocation::add($totals[$currencyId] ?? '0', $allocation->applied_amount);
        }

        return $totals;
    }

    private function assertInvoiceBelongs(CustomerReceipt $receipt, SalesInvoice $invoice): void
    {
        if ($invoice->status !== SalesInvoiceStatus::Posted) {
            $this->fail('Only a posted invoice can be allocated.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
        }

        if ($this->invoiceChainRegistrationService->salesInvoiceIsDisputed($invoice)) {
            $this->fail('This invoice is disputed and cannot receive a customer receipt.', 'SALES_INVOICE_DISPUTED');
        }

        if ((string) $invoice->customer_id !== (string) $receipt->customer_id) {
            $this->fail('Invoice customer does not match this receipt.', 'CUSTOMER_RECEIPT_INVOICE_MISMATCH');
        }
    }

    private function assertInvoiceOpenFor(CustomerReceipt $receipt, SalesInvoice $invoice, string $amount): void
    {
        $this->assertInvoiceBelongs($receipt, $invoice);

        if (! PaymentAllocation::isPositive($amount) || PaymentAllocation::exceeds($amount, $invoice->net_to_pay)) {
            $this->fail('Allocation exceeds the invoice open amount.', 'CUSTOMER_RECEIPT_INVOICE_NOT_OPEN');
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

    private function lockDraft(CustomerReceipt $receipt): CustomerReceipt
    {
        $locked = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== CustomerReceiptStatus::Draft) {
            $this->fail('Only a draft customer receipt can be changed.', 'CUSTOMER_RECEIPT_NOT_DRAFT');
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
