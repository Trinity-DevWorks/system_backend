<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Services;

use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Sales\SalesOrder\Enums\SalesOrderStatus;
use App\Modules\Sales\SalesOrder\Models\SalesOrder;
use App\Modules\Sales\SalesOrder\Models\SalesOrderLine;
use Illuminate\Support\Collection;

/**
 * Posted sales invoices are the only documents that consume an order line.
 * Draft invoices do not, so two drafts may overlap until one of them posts.
 */
class SalesOrderFulfillment
{
    public function applyInvoice(SalesInvoice $invoice, bool $reverse): void
    {
        if ($invoice->sales_order_id === null) {
            return;
        }

        $invoiceLines = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->whereNotNull('sales_order_line_id')
            ->get();

        if ($invoiceLines->isEmpty()) {
            return;
        }

        $order = SalesOrder::query()->whereKey($invoice->sales_order_id)->lockForUpdate()->firstOrFail();
        if (! in_array($order->status, [
            SalesOrderStatus::Confirmed,
            SalesOrderStatus::PartiallyInvoiced,
            SalesOrderStatus::Invoiced,
        ], true)) {
            abort(422, 'Only a confirmed sales order can be invoiced.', ['X-Error-Code' => 'SALES_ORDER_NOT_CONFIRMED']);
        }

        /** @var Collection<int, SalesOrderLine> $orderLines */
        $orderLines = SalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        /** @var array<int, string> $deltas */
        $deltas = [];
        foreach ($invoiceLines as $line) {
            $orderLine = $orderLines->get($line->sales_order_line_id);
            if (! $orderLine instanceof SalesOrderLine
                || (string) $orderLine->item_id !== (string) $line->item_id
                || (int) ($orderLine->item_uom_id ?? 0) !== (int) ($line->item_uom_id ?? 0)) {
                abort(422, 'Invoice line does not match the sales order line.', [
                    'X-Error-Code' => 'SALES_INVOICE_ORDER_LINE_MISMATCH',
                ]);
            }
            $id = (int) $orderLine->id;
            $deltas[$id] = bcadd($deltas[$id] ?? '0', (string) $line->base_quantity, 6);
        }

        foreach ($orderLines as $orderLine) {
            $delta = $deltas[(int) $orderLine->id] ?? null;
            if ($delta === null) {
                continue;
            }
            $next = $reverse
                ? bcsub((string) $orderLine->invoiced_base_quantity, $delta, 6)
                : bcadd((string) $orderLine->invoiced_base_quantity, $delta, 6);
            if (bccomp($next, '0', 6) < 0 || (! $reverse && bccomp($next, (string) $orderLine->base_quantity, 6) > 0)) {
                abort(422, 'Invoiced quantity cannot exceed the sales order line.', [
                    'X-Error-Code' => 'SALES_INVOICE_ORDER_QTY_EXCEEDED',
                ]);
            }
            $orderLine->invoiced_base_quantity = $next;
            $orderLine->save();
        }

        $this->refreshStatus($order, $orderLines);
    }

    /**
     * @param  Collection<int, SalesOrderLine>  $lines
     */
    public function refreshStatus(SalesOrder $order, Collection $lines): void
    {
        if (! in_array($order->status, [
            SalesOrderStatus::Confirmed,
            SalesOrderStatus::PartiallyInvoiced,
            SalesOrderStatus::Invoiced,
        ], true)) {
            return;
        }

        $any = false;
        $all = $lines->isNotEmpty();
        foreach ($lines as $line) {
            $invoiced = (string) $line->invoiced_base_quantity;
            if (bccomp($invoiced, '0', 6) > 0) {
                $any = true;
            }
            if (bccomp($invoiced, (string) $line->base_quantity, 6) < 0) {
                $all = false;
            }
        }

        $status = match (true) {
            $all && $any => SalesOrderStatus::Invoiced,
            $any => SalesOrderStatus::PartiallyInvoiced,
            default => SalesOrderStatus::Confirmed,
        };

        if ($order->status !== $status) {
            $order->update(['status' => $status]);
        }
    }
}
