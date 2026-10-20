<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesQuotation\Services;

use App\Modules\Currency\Models\Currency;
use App\Modules\Currency\Services\ExchangeRateService;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\SalesOrder\Services\SalesOrderService;
use App\Modules\Sales\SalesQuotation\Enums\SalesQuotationStatus;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotation;
use App\Modules\Sales\SalesQuotation\Models\SalesQuotationLine;
use App\Modules\Sales\SalesQuotation\Support\SalesQuotationRules;
use App\Modules\Sales\Support\CommercialLineBuilder;
use App\Support\ExchangeRateSnapshot;
use App\Support\SequentialCodeGenerator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesQuotationService
{
    public function __construct(
        private readonly SalesQuotationQueryService $queryService,
        private readonly ExchangeRateService $exchangeRateService,
        private readonly SalesOrderService $salesOrderService,
    ) {}

    /**
     * @param  array{status?:string,customer_id?:string,search?:string,from?:string,to?:string}  $filters
     * @return LengthAwarePaginator<int, SalesQuotation>
     */
    public function list(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        return $this->queryService->paginate($filters, $perPage);
    }

    public function find(string $id): SalesQuotation
    {
        return SalesQuotation::query()
            ->with([
                'customer',
                'currency',
                'salesman',
                'paymentTerm',
                'salesOrder',
                'createdByUser',
                'sentByUser',
                'acceptedByUser',
                'rejectedByUser',
                'lines.item.itemType',
                'lines.item.vatGroup',
                'lines.itemUom.uom',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SalesQuotation
    {
        $customer = SalesQuotationRules::assertCustomer((string) $data['customer_id']);

        return DB::transaction(function () use ($data, $userId, $customer): SalesQuotation {
            $quotation = SalesQuotation::query()->create([
                ...$this->normalizeHeader($data, $customer, null),
                'status' => SalesQuotationStatus::Draft,
                'created_by' => $userId,
                'quotation_number' => SequentialCodeGenerator::next(SalesQuotation::class, 'quotation_number', 'QT-'),
            ]);

            $this->replaceLines($quotation, $data['lines'] ?? []);

            return $this->find($quotation->id);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SalesQuotation $quotation, array $data): SalesQuotation
    {
        return DB::transaction(function () use ($quotation, $data): SalesQuotation {
            $quotation = $this->lockDraft($quotation);
            $customerId = array_key_exists('customer_id', $data)
                ? (string) $data['customer_id']
                : (string) $quotation->customer_id;
            $customer = SalesQuotationRules::assertCustomer($customerId);
            $merged = array_merge($quotation->only([
                'customer_id',
                'currency_id',
                'salesman_id',
                'payment_terms_id',
                'quotation_date',
                'valid_until',
                'exchange_rate',
                'notes',
            ]), $data);
            $quotation->update($this->normalizeHeader($merged, $customer, $quotation));
            $this->recalculateTotals($quotation->fresh() ?? $quotation);

            return $this->find($quotation->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, SalesQuotationLine>
     */
    public function syncLines(SalesQuotation $quotation, array $lines): Collection
    {
        return DB::transaction(function () use ($quotation, $lines): Collection {
            $quotation = $this->lockDraft($quotation);
            $this->replaceLines($quotation, $lines);

            return SalesQuotationLine::query()
                ->where('sales_quotation_id', $quotation->id)
                ->with(['item.itemType', 'item.vatGroup', 'itemUom.uom'])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
        });
    }

    public function delete(SalesQuotation $quotation): void
    {
        DB::transaction(function () use ($quotation): void {
            $quotation = $this->lockDraft($quotation);
            $quotation->delete();
        });
    }

    public function send(SalesQuotation $quotation, ?string $userId): SalesQuotation
    {
        return DB::transaction(function () use ($quotation, $userId): SalesQuotation {
            $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesQuotationStatus::Draft) {
                abort(422, 'Only a draft quotation can be sent.', ['X-Error-Code' => 'SALES_QUOTATION_NOT_DRAFT']);
            }
            $locked->update([
                'status' => SalesQuotationStatus::Sent,
                'sent_by' => $userId,
                'sent_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function accept(SalesQuotation $quotation, ?string $userId): SalesQuotation
    {
        return DB::transaction(function () use ($quotation, $userId): SalesQuotation {
            $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesQuotationStatus::Sent) {
                abort(422, 'Only a sent quotation can be accepted.', ['X-Error-Code' => 'SALES_QUOTATION_NOT_SENT']);
            }
            $locked->update([
                'status' => SalesQuotationStatus::Accepted,
                'accepted_by' => $userId,
                'accepted_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function reject(SalesQuotation $quotation, ?string $userId): SalesQuotation
    {
        return DB::transaction(function () use ($quotation, $userId): SalesQuotation {
            $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== SalesQuotationStatus::Sent) {
                abort(422, 'Only a sent quotation can be rejected.', ['X-Error-Code' => 'SALES_QUOTATION_NOT_SENT']);
            }
            $locked->update([
                'status' => SalesQuotationStatus::Rejected,
                'rejected_by' => $userId,
                'rejected_at' => now(),
            ]);

            return $this->find($locked->id);
        });
    }

    public function expire(SalesQuotation $quotation): SalesQuotation
    {
        return DB::transaction(function () use ($quotation): SalesQuotation {
            $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [SalesQuotationStatus::Sent, SalesQuotationStatus::Accepted], true)) {
                abort(422, 'Only a sent or accepted quotation can expire.', ['X-Error-Code' => 'SALES_QUOTATION_NOT_OPEN']);
            }
            $locked->update(['status' => SalesQuotationStatus::Expired]);

            return $this->find($locked->id);
        });
    }

    public function convert(SalesQuotation $quotation, ?string $userId): SalesQuotation
    {
        return DB::transaction(function () use ($quotation, $userId): SalesQuotation {
            $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->sales_order_id !== null || $locked->status === SalesQuotationStatus::Converted) {
                abort(422, 'This quotation has already been converted.', ['X-Error-Code' => 'SALES_QUOTATION_ALREADY_CONVERTED']);
            }
            if ($locked->status !== SalesQuotationStatus::Accepted) {
                abort(422, 'Only an accepted quotation can be converted.', ['X-Error-Code' => 'SALES_QUOTATION_NOT_ACCEPTED']);
            }

            $order = $this->salesOrderService->createFromQuotation($locked, $userId);
            $locked->update([
                'status' => SalesQuotationStatus::Converted,
                'sales_order_id' => $order->id,
            ]);

            return $this->find($locked->id);
        });
    }

    private function transition(
        SalesQuotation $quotation,
        SalesQuotationStatus $from,
        SalesQuotationStatus $to,
        string $code,
        string $message,
    ): SalesQuotation {
        return DB::transaction(function () use ($quotation, $from, $to, $code, $message): SalesQuotation {
            $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                abort(422, $message, ['X-Error-Code' => $code]);
            }
            $locked->update(['status' => $to]);

            return $this->find($locked->id);
        });
    }

    private function lockDraft(SalesQuotation $quotation): SalesQuotation
    {
        $locked = SalesQuotation::query()->whereKey($quotation->id)->lockForUpdate()->firstOrFail();
        SalesQuotationRules::assertDraft($locked);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeHeader(array $data, Customer $customer, ?SalesQuotation $existing): array
    {
        $quotationDate = isset($data['quotation_date']) && $data['quotation_date'] !== ''
            ? Carbon::parse((string) $data['quotation_date'])->toDateString()
            : ($existing?->quotation_date?->toDateString() ?? now()->toDateString());

        $currencyId = isset($data['currency_id']) && $data['currency_id'] !== null && $data['currency_id'] !== ''
            ? (int) $data['currency_id']
            : ($existing !== null ? (int) $existing->currency_id : (int) (Currency::getPrimary()?->id ?? 0));

        $exchangeRate = ExchangeRateSnapshot::resolve(
            $this->exchangeRateService,
            $currencyId,
            $data['exchange_rate'] ?? ($existing !== null && (int) $existing->currency_id === $currencyId ? $existing->exchange_rate : null),
            'SALES_QUOTATION',
        );

        $salesmanId = array_key_exists('salesman_id', $data)
            ? ($data['salesman_id'] !== null && $data['salesman_id'] !== '' ? (string) $data['salesman_id'] : null)
            : ($existing?->salesman_id ?? $customer->salesman_id);

        $paymentTermsId = array_key_exists('payment_terms_id', $data)
            ? ($data['payment_terms_id'] !== null && $data['payment_terms_id'] !== '' ? (int) $data['payment_terms_id'] : null)
            : ($existing?->payment_terms_id !== null ? (int) $existing->payment_terms_id : $customer->payment_terms_id);

        $validUntil = array_key_exists('valid_until', $data)
            ? ($data['valid_until'] !== null && $data['valid_until'] !== '' ? Carbon::parse((string) $data['valid_until'])->toDateString() : null)
            : $existing?->valid_until?->toDateString();

        return [
            'customer_id' => (string) $customer->id,
            'currency_id' => $currencyId,
            'salesman_id' => $salesmanId,
            'payment_terms_id' => $paymentTermsId,
            'quotation_date' => $quotationDate,
            'valid_until' => $validUntil,
            'exchange_rate' => $exchangeRate,
            'notes' => CommercialLineBuilder::nullableString($data['notes'] ?? $existing?->notes),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    private function replaceLines(SalesQuotation $quotation, array $lines): void
    {
        $quotation->loadMissing('customer');
        $customer = $quotation->customer ?? SalesQuotationRules::assertCustomer((string) $quotation->customer_id);
        $rows = CommercialLineBuilder::rows($lines, $customer, $quotation->quotation_date->toDateString(), 'SALES_QUOTATION');

        SalesQuotationLine::query()->where('sales_quotation_id', $quotation->id)->delete();
        foreach ($rows as $row) {
            SalesQuotationLine::query()->create([
                ...$row,
                'sales_quotation_id' => $quotation->id,
            ]);
        }

        $this->recalculateTotals($quotation);
    }

    private function recalculateTotals(SalesQuotation $quotation): void
    {
        $rows = SalesQuotationLine::query()->where('sales_quotation_id', $quotation->id)->get();
        $quotation->update(CommercialLineBuilder::totals($rows));
    }
}
