<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\Services;

use App\Modules\Sales\DeliveryNote\Enums\DeliveryNoteStatus;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNoteLine;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use Illuminate\Support\Collection;

/**
 * Invoice lines made from delivery note lines: they bill goods that already left the warehouse,
 * so they never move stock; posting raises the delivery line's invoiced quantity (up to what was delivered).
 */
class DeliveryNoteInvoicing
{
    /**
     * Validates a draft invoice line's link to a delivery note line.
     */
    public function assertLinkable(int $deliveryNoteLineId, string $customerId, string $itemId, ?int $itemUomId): void
    {
        $line = DeliveryNoteLine::query()->with('deliveryNote:id,customer_id,status')->find($deliveryNoteLineId);
        $note = $line?->deliveryNote;
        if (! $line instanceof DeliveryNoteLine || ! $note instanceof DeliveryNote || $note->status !== DeliveryNoteStatus::Posted) {
            abort(422, 'Invoice line must come from a posted delivery note.', ['X-Error-Code' => 'SALES_INVOICE_DELIVERY_LINE_INVALID']);
        }
        if ((string) $note->customer_id !== $customerId
            || (string) $line->item_id !== $itemId
            || (int) ($line->item_uom_id ?? 0) !== (int) ($itemUomId ?? 0)) {
            abort(422, 'Invoice line does not match the delivery note line.', ['X-Error-Code' => 'SALES_INVOICE_DELIVERY_LINE_MISMATCH']);
        }
    }

    public function applyInvoice(SalesInvoice $invoice, bool $reverse): void
    {
        /** @var Collection<int, SalesInvoiceLine> $invoiceLines */
        $invoiceLines = SalesInvoiceLine::query()
            ->where('sales_invoice_id', $invoice->id)
            ->whereNotNull('delivery_note_line_id')
            ->get();
        if ($invoiceLines->isEmpty()) {
            return;
        }

        /** @var array<int, string> $deltas */
        $deltas = [];
        foreach ($invoiceLines as $line) {
            $id = (int) $line->delivery_note_line_id;
            $deltas[$id] = bcadd($deltas[$id] ?? '0', (string) $line->base_quantity, 6);
        }

        $noteLines = DeliveryNoteLine::query()->whereIn('id', array_keys($deltas))->lockForUpdate()->get()->keyBy('id');
        foreach ($deltas as $id => $delta) {
            $noteLine = $noteLines->get($id);
            if (! $noteLine instanceof DeliveryNoteLine) {
                abort(422, 'Invoice line must come from a posted delivery note.', ['X-Error-Code' => 'SALES_INVOICE_DELIVERY_LINE_INVALID']);
            }
            $next = $reverse
                ? bcsub((string) $noteLine->invoiced_base_quantity, $delta, 6)
                : bcadd((string) $noteLine->invoiced_base_quantity, $delta, 6);
            if (bccomp($next, '0', 6) < 0) {
                $next = '0.000000';
            }
            if (! $reverse && bccomp($next, (string) $noteLine->base_quantity, 6) > 0) {
                abort(422, 'Invoiced quantity cannot exceed what the delivery note delivered.', [
                    'X-Error-Code' => 'SALES_INVOICE_DELIVERY_QTY_EXCEEDED',
                ]);
            }
            $noteLine->invoiced_base_quantity = $next;
            $noteLine->save();
        }
    }
}
