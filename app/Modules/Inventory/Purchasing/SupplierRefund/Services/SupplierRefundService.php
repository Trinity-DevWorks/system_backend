<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\SupplierRefund\Services;

use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteStatus;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\SupplierRefund\Enums\SupplierRefundStatus;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefund;
use App\Modules\Inventory\Purchasing\SupplierRefund\Models\SupplierRefundAllocation;
use App\Modules\Inventory\Purchasing\Support\PurchaseInvoiceRules;
use App\Modules\PaymentMethod\Enums\PaymentMethodType;
use App\Modules\PaymentMethod\Models\PaymentMethod;
use App\Modules\Supplier\Enums\LedgerReferenceType;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Support\CurrencyCashRound;
use App\Support\ExchangeRateSnapshot;
use App\Support\ListPagination;
use App\Support\PaymentAllocation;
use App\Support\SequentialCodeGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SupplierRefundService
{
    public function __construct(
        private readonly SupplierLedgerService $supplierLedgerService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SupplierRefund>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = SupplierRefund::query()
            ->with([
                'supplier:id,supplier_code,name,phone,is_active',
                'currency:id,code,name,symbol',
                'paymentMethod:id,name,code,type,requires_reference,is_active',
                'createdByUser:id,name,email',
                'postedByUser:id,name,email',
            ])
            ->withCount('allocations');

        if (! empty($filters['status'])) {
            $status = SupplierRefundStatus::tryFrom((string) $filters['status']);
            if ($status) {
                $query->where('status', $status->value);
            }
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (string) $filters['supplier_id']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['refund_number']);
        if (! empty($filters['from'])) {
            $query->where('refund_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('refund_date', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    public function find(string $id): SupplierRefund
    {
        return SupplierRefund::query()
            ->with([
                'supplier',
                'currency',
                'paymentMethod',
                'createdByUser',
                'postedByUser',
                'allocations.purchaseDebitNote',
            ])
            ->withCount('allocations')
            ->findOrFail($id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openDebitNotes(string $customerId, int $currencyId): array
    {
        if ($customerId === '' || $currencyId <= 0) {
            $this->fail('Supplier and currency are required.', 'SUPPLIER_REFUND_FILTER_REQUIRED');
        }

        return PurchaseDebitNote::query()
            ->with('currency:id,code,name,symbol')
            ->where('supplier_id', $customerId)
            ->where('currency_id', $currencyId)
            ->where('status', PurchaseDebitNoteStatus::Posted->value)
            ->where('open_credit', '>', 0)
            ->orderBy('debit_date')
            ->orderBy('debit_note_number')
            ->get()
            ->map(fn (PurchaseDebitNote $note): array => [
                'id' => $note->id,
                'invoice_number' => $note->debit_note_number,
                'debit_note_number' => $note->debit_note_number,
                'invoice_date' => $note->debit_date?->toDateString(),
                'debit_date' => $note->debit_date?->toDateString(),
                'grand_total' => (string) $note->grand_total,
                'paid_total' => (string) $note->refunded_total,
                'net_to_pay' => (string) $note->open_credit,
                'open_credit' => (string) $note->open_credit,
                'currency_id' => $note->currency_id,
                'currency' => $note->currency ? [
                    'id' => $note->currency->id,
                    'code' => $note->currency->code,
                    'name' => $note->currency->name,
                    'symbol' => $note->currency->symbol,
                ] : null,
                'supplier_id' => $note->supplier_id,
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SupplierRefund
    {
        PurchaseInvoiceRules::assertSupplier((string) $data['supplier_id']);
        $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);

        return DB::transaction(function () use ($data, $userId): SupplierRefund {
            $refund = SupplierRefund::query()->create([
                ...$this->headerAttributes($data, null),
                'supplier_id' => (string) $data['supplier_id'],
                'refund_number' => SequentialCodeGenerator::next(SupplierRefund::class, 'refund_number', 'SRF-'),
                'status' => SupplierRefundStatus::Draft,
                'created_by' => $userId,
            ]);
            $this->replaceAllocations($refund, $data['allocations'] ?? []);

            return $this->find($refund->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SupplierRefund $refund, array $data): SupplierRefund
    {
        return DB::transaction(function () use ($refund, $data): SupplierRefund {
            $locked = $this->lockDraft($refund);
            PurchaseInvoiceRules::assertSupplier((string) $data['supplier_id']);
            $this->assertPaymentMethod((int) $data['payment_method_id'], $data['reference'] ?? null);
            $partyChanged = (string) $data['supplier_id'] !== (string) $locked->supplier_id
                || (int) $data['currency_id'] !== (int) $locked->currency_id;
            if ($partyChanged && $locked->allocations()->exists()) {
                $this->fail('Remove allocations before changing the supplier or currency.', 'SUPPLIER_REFUND_HEADER_LOCKED');
            }
            $locked->update([
                ...$this->headerAttributes($data, $locked),
                'supplier_id' => (string) $data['supplier_id'],
            ]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $allocations
     */
    public function syncAllocations(SupplierRefund $refund, array $allocations): SupplierRefund
    {
        return DB::transaction(function () use ($refund, $allocations): SupplierRefund {
            $locked = $this->lockDraft($refund);
            $this->replaceAllocations($locked, $allocations);

            return $this->find($locked->id);
        });
    }

    public function delete(SupplierRefund $refund): void
    {
        DB::transaction(function () use ($refund): void {
            $this->lockDraft($refund)->delete();
        });
    }

    public function post(SupplierRefund $refund, ?string $userId): SupplierRefund
    {
        return DB::transaction(function () use ($refund, $userId): SupplierRefund {
            $locked = SupplierRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SupplierRefundStatus::Draft) {
                $this->fail('Only a draft supplier refund can be posted.', 'SUPPLIER_REFUND_NOT_DRAFT');
            }
            if (! PaymentAllocation::isPositive($locked->amount)) {
                $this->fail('Refund amount must be greater than zero.', 'SUPPLIER_REFUND_AMOUNT_INVALID');
            }

            $this->applyCashRounding($locked);
            $locked->refresh();
            $allocations = SupplierRefundAllocation::query()->where('supplier_refund_id', $locked->id)->orderBy('id')->get();
            if ($allocations->isEmpty() || ! PaymentAllocation::same($locked->amount, PaymentAllocation::sum($allocations->pluck('amount')->all()))) {
                $this->fail('Allocations must add up to the refund amount.', 'SUPPLIER_REFUND_ALLOCATION_MISMATCH');
            }

            $this->assertPaymentMethod((int) $locked->payment_method_id, $locked->reference);
            $customer = PurchaseInvoiceRules::assertSupplier((string) $locked->supplier_id);
            $ids = $allocations->pluck('purchase_debit_note_id')->map(fn ($id): string => (string) $id)->all();
            sort($ids);
            $notes = PurchaseDebitNote::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($notes->count() !== count($ids)) {
                $this->fail('An allocated debit note does not match this refund.', 'SUPPLIER_REFUND_DEBIT_NOTE_MISMATCH');
            }

            foreach ($allocations as $allocation) {
                $note = $notes->get($allocation->purchase_debit_note_id);
                if (! $note instanceof PurchaseDebitNote) {
                    $this->fail('An allocated debit note does not match this refund.', 'SUPPLIER_REFUND_DEBIT_NOTE_MISMATCH');
                }
                $this->assertNoteOpen($locked, $note, (string) $allocation->amount);
                $refunded = PaymentAllocation::add($note->refunded_total, $allocation->amount);
                $open = PaymentAllocation::subtract($note->open_credit, $allocation->amount);
                if ($open === null) {
                    $this->fail('Allocation exceeds the debit note open amount.', 'SUPPLIER_REFUND_DEBIT_NOTE_NOT_OPEN');
                }
                $note->update(['refunded_total' => $refunded, 'open_credit' => $open]);
            }

            $this->supplierLedgerService->postEntry(
                $customer,
                (int) $locked->currency_id,
                '0',
                (string) $locked->amount,
                LedgerReferenceType::Refund,
                (string) $locked->id,
                $locked->refund_date->toDateString(),
            );
            $this->journalPostingService->supplierRefund($locked, $userId);
            $locked->update([
                'status' => SupplierRefundStatus::Posted,
                'posted_by' => $userId,
                'posted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reverse(SupplierRefund $refund, ?string $userId = null): SupplierRefund
    {
        return DB::transaction(function () use ($refund, $userId): SupplierRefund {
            $locked = SupplierRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SupplierRefundStatus::Posted) {
                $this->fail('Only a posted supplier refund can be reversed.', 'SUPPLIER_REFUND_NOT_POSTED');
            }

            $allocations = SupplierRefundAllocation::query()->where('supplier_refund_id', $locked->id)->orderBy('id')->get();
            $ids = $allocations->pluck('purchase_debit_note_id')->map(fn ($id): string => (string) $id)->all();
            sort($ids);
            $notes = PurchaseDebitNote::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            foreach ($allocations as $allocation) {
                $note = $notes->get($allocation->purchase_debit_note_id);
                if (! $note instanceof PurchaseDebitNote || $note->status !== PurchaseDebitNoteStatus::Posted) {
                    $this->fail('An allocated debit note is no longer posted.', 'SUPPLIER_REFUND_DEBIT_NOTE_NOT_OPEN');
                }
                $refunded = PaymentAllocation::subtract($note->refunded_total, $allocation->amount);
                if ($refunded === null) {
                    $this->fail('This refund cannot be reversed because a debit note refunded total is too low.', 'SUPPLIER_REFUND_DEBIT_NOTE_NOT_OPEN');
                }
                $note->update([
                    'refunded_total' => $refunded,
                    'open_credit' => PaymentAllocation::add($note->open_credit, $allocation->amount),
                ]);
            }

            $customer = Supplier::query()->findOrFail($locked->supplier_id);
            $this->supplierLedgerService->postEntry(
                $customer,
                (int) $locked->currency_id,
                (string) $locked->amount,
                '0',
                LedgerReferenceType::Refund,
                (string) $locked->id,
                now()->toDateString(),
            );
            $this->journalPostingService->reverse(SupplierRefund::REFERENCE_TYPE, (string) $locked->id, $userId);
            $locked->update(['status' => SupplierRefundStatus::Reversed]);

            return $this->find($locked->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function headerAttributes(array $data, ?SupplierRefund $existing): array
    {
        $currencyId = (int) $data['currency_id'];

        return [
            'currency_id' => $currencyId,
            'exchange_rate' => ExchangeRateSnapshot::resolve(
                $this->exchangeRateService,
                $currencyId,
                $data['exchange_rate'] ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
                'CUSTOMER_REFUND',
            ),
            'payment_method_id' => (int) $data['payment_method_id'],
            'refund_date' => Carbon::parse((string) $data['refund_date'])->toDateString(),
            'amount' => PaymentAllocation::normalize($data['amount']),
            'reference' => $this->nullableString($data['reference'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function replaceAllocations(SupplierRefund $refund, array $rows): void
    {
        $seen = [];
        $payload = [];
        foreach ($rows as $row) {
            $noteId = (string) ($row['purchase_debit_note_id'] ?? '');
            if ($noteId === '' || isset($seen[$noteId])) {
                $this->fail('Each debit note can be allocated only once on a refund.', 'SUPPLIER_REFUND_DEBIT_NOTE_MISMATCH');
            }
            $seen[$noteId] = true;
            $amount = PaymentAllocation::normalize($row['amount'] ?? 0);
            if (! PaymentAllocation::isPositive($amount)) {
                $this->fail('Each allocation must be greater than zero.', 'SUPPLIER_REFUND_DEBIT_NOTE_NOT_OPEN');
            }
            $payload[] = ['purchase_debit_note_id' => $noteId, 'amount' => $amount];
        }

        $notes = PurchaseDebitNote::query()->whereIn('id', array_keys($seen))->get()->keyBy('id');
        foreach ($payload as $row) {
            $note = $notes->get($row['purchase_debit_note_id']);
            if (! $note instanceof PurchaseDebitNote) {
                $this->fail('An allocated debit note does not match this refund.', 'SUPPLIER_REFUND_DEBIT_NOTE_MISMATCH');
            }
            $this->assertNoteOpen($refund, $note, $row['amount']);
        }

        $refund->allocations()->delete();
        foreach ($payload as $row) {
            $note = $notes->get($row['purchase_debit_note_id']);
            SupplierRefundAllocation::query()->create([
                'supplier_refund_id' => $refund->id,
                'purchase_debit_note_id' => $row['purchase_debit_note_id'],
                'amount' => $row['amount'],
                'applied_amount' => $row['amount'],
                'applied_exchange_rate' => (string) ($note?->exchange_rate ?? $refund->exchange_rate),
                'exchange_difference' => PaymentAllocation::normalize(0),
            ]);
        }
        $refund->touch();
    }

    private function applyCashRounding(SupplierRefund $refund): void
    {
        $currency = Currency::query()->find((int) $refund->currency_id);
        $rounded = CurrencyCashRound::round((string) $refund->amount, $currency?->smallest_unit, $currency?->round_limit);
        if (PaymentAllocation::same($rounded, $refund->amount)) {
            return;
        }
        if (! PaymentAllocation::isPositive($rounded)) {
            $this->fail('Refund amount must be greater than zero.', 'SUPPLIER_REFUND_AMOUNT_INVALID');
        }
        $allocations = SupplierRefundAllocation::query()->where('supplier_refund_id', $refund->id)->orderBy('id')->get();
        $next = PaymentAllocation::amountsForRoundedHeader(
            $allocations->map(fn (SupplierRefundAllocation $row): string => (string) $row->amount)->all(),
            $rounded,
        );
        if ($next === null) {
            $this->fail('Cash rounding cannot be applied to these allocations.', 'SUPPLIER_REFUND_ALLOCATION_MISMATCH');
        }
        foreach ($allocations->values() as $index => $allocation) {
            $allocation->update([
                'amount' => $next[$index],
                'applied_amount' => $next[$index],
            ]);
        }
        $refund->update(['amount' => $rounded]);
    }

    private function assertNoteOpen(SupplierRefund $refund, PurchaseDebitNote $note, string $amount): void
    {
        if ($note->status !== PurchaseDebitNoteStatus::Posted || ! PaymentAllocation::isPositive($note->open_credit)) {
            $this->fail('Only a posted debit note with open credit can be refunded.', 'SUPPLIER_REFUND_DEBIT_NOTE_NOT_OPEN');
        }
        if ((string) $note->supplier_id !== (string) $refund->supplier_id || (int) $note->currency_id !== (int) $refund->currency_id) {
            $this->fail('Debit note customer or currency does not match this refund.', 'SUPPLIER_REFUND_DEBIT_NOTE_MISMATCH');
        }
        if (PaymentAllocation::exceeds($amount, $note->open_credit)) {
            $this->fail('Allocation exceeds the debit note open amount.', 'SUPPLIER_REFUND_DEBIT_NOTE_NOT_OPEN');
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

    private function lockDraft(SupplierRefund $refund): SupplierRefund
    {
        $locked = SupplierRefund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== SupplierRefundStatus::Draft) {
            $this->fail('Only a draft supplier refund can be changed.', 'SUPPLIER_REFUND_NOT_DRAFT');
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
