<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesInvoice\Services;

use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Stock\Models\StockBalance;
use App\Modules\Inventory\Stock\Services\StockReservationService;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Warehouse\Services\WarehouseService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Entry point for sales invoices. The work is done by the posting pipeline's steps:
 * SalesInvoiceDraftService (price and tax), SalesInvoiceStockPoster (stock), SalesInvoicePaymentService
 * (payments), and SalesInvoicePostingPipeline (post / reverse, which also writes the customer account and
 * the journal entry).
 */
class SalesInvoiceService
{
    public function __construct(
        private readonly SalesInvoiceQueryService $queryService,
        private readonly WarehouseService $warehouseService,
        private readonly SalesInvoiceDraftService $draft,
        private readonly SalesInvoicePaymentService $payments,
        private readonly SalesInvoicePostingPipeline $pipeline,
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
        return $this->queryService->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SalesInvoice
    {
        return $this->draft->create($data, $userId);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateHeader(SalesInvoice $invoice, array $data): SalesInvoice
    {
        return $this->draft->updateHeader($invoice, $data);
    }

    /**
     * Saves a draft in one call: the header and, when sent, the lines, in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDocument(SalesInvoice $invoice, array $data): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $data): SalesInvoice {
            $header = Arr::except($data, ['lines']);
            if ($header !== []) {
                $this->draft->updateHeader($invoice, $header);
            }
            if (array_key_exists('lines', $data)) {
                $this->draft->syncLines($invoice, is_array($data['lines']) ? array_values($data['lines']) : []);
            }

            return $this->find((string) $invoice->id);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return Collection<int, SalesInvoiceLine>
     */
    public function syncLines(SalesInvoice $invoice, array $lines): Collection
    {
        return $this->draft->syncLines($invoice, $lines);
    }

    public function delete(SalesInvoice $invoice): void
    {
        $this->draft->delete($invoice);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncPayments(SalesInvoice $invoice, array $rows, bool $keepExtraAsCredit = false): SalesInvoice
    {
        return $this->payments->syncPayments($invoice, $rows, $keepExtraAsCredit);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function recordPayments(SalesInvoice $invoice, array $rows, ?string $paymentDate, ?string $userId, bool $keepExtraAsCredit = false): SalesInvoice
    {
        return $this->payments->recordPayments($invoice, $rows, $paymentDate, $userId, $keepExtraAsCredit);
    }

    /**
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval
     */
    public function post(SalesInvoice $invoice, ?string $userId, ?array $approval = null): SalesInvoice
    {
        return $this->pipeline->post($invoice, $userId, $approval);
    }

    /**
     * Posting in one call: saves what was sent (header, lines, payments; a new invoice when `$invoice` is null)
     * and posts it, in one transaction, so either all of it happens or none of it. `$requestId` makes a retry
     * safe: the same request posts once, and a repeat returns the posted invoice.
     *
     * @param  array<string, mixed>  $data
     * @param  array{approver_id?: mixed, pin?: mixed}|null  $approval
     */
    public function postDocument(?SalesInvoice $invoice, array $data, ?string $requestId, ?string $userId, ?array $approval = null): SalesInvoice
    {
        try {
            return DB::transaction(function () use ($invoice, $data, $requestId, $userId, $approval): SalesInvoice {
                if ($requestId !== null) {
                    $done = SalesInvoice::query()->where('post_request_id', $requestId)->first();
                    if ($done !== null && $done->status !== SalesInvoiceStatus::Draft) {
                        return $this->find((string) $done->id);
                    }
                }

                $document = Arr::except($data, ['payments', 'keep_extra_as_credit', 'approval']);
                if ($invoice === null) {
                    $invoice = $this->draft->create($document, $userId);
                } elseif ($document !== []) {
                    $this->saveDocument($invoice, $document);
                }
                if (array_key_exists('payments', $data)) {
                    $this->payments->syncPayments($invoice, is_array($data['payments']) ? array_values($data['payments']) : [], (bool) ($data['keep_extra_as_credit'] ?? false));
                }
                if ($requestId !== null) {
                    SalesInvoice::query()->whereKey($invoice->id)->update(['post_request_id' => $requestId]);
                }

                return $this->pipeline->post($invoice, $userId, $approval);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The same request is being posted by a parallel call: return what it posted.
            $id = $requestId !== null ? SalesInvoice::query()->where('post_request_id', $requestId)->value('id') : null;
            if ($id === null) {
                throw $e;
            }

            return $this->find((string) $id);
        }
    }

    public function reverse(SalesInvoice $invoice, ?string $userId): SalesInvoice
    {
        return $this->pipeline->reverse($invoice, $userId);
    }

    public function bounceCounterCheque(CustomerCheque $cheque, ?string $userId, string $date): void
    {
        $this->payments->bounceCounterCheque($cheque, $userId, $date);
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

        $reservations = app(StockReservationService::class);
        foreach ($byWarehouse as $wid => $row) {
            $reserved = $reservations->reservedFor($itemId, (int) $wid);
            $byWarehouse[$wid]['reserved'] = $reserved;
            $byWarehouse[$wid]['available'] = bcsub($row['on_hand'], $reserved, 6);
        }

        return array_values($byWarehouse);
    }
}
