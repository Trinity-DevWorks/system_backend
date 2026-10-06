<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\DTOs;

use App\Modules\InvoiceProof\Enums\InvoiceProofType;
use App\Modules\InvoiceProof\Models\InvoiceChainRegistration;
use App\Modules\Sales\SalesCreditNote\Enums\SalesCreditNoteStatus;
use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNote;
use App\Modules\Sales\SalesInvoice\Enums\SalesInvoiceStatus;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoice;

readonly class SalesCreditNoteResponseData
{
    public static function fromModel(SalesCreditNote $note, bool $includeLines = true): array
    {
        $note->loadMissing([
            'customer:id,customer_code,name',
            'warehouse:id,name,shortcut_name',
            'currency:id,code,name,symbol',
            'salesInvoice:id,invoice_number,status,grand_total,paid_total,credited_total,net_to_pay',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $invoice = $note->salesInvoice;
        $status = $note->status instanceof SalesCreditNoteStatus
            ? $note->status->value
            : (string) $note->status;

        $payload = [
            'id' => $note->id,
            'credit_note_number' => $note->credit_note_number,
            'sales_invoice_id' => $note->sales_invoice_id,
            'customer_id' => $note->customer_id,
            'warehouse_id' => $note->warehouse_id,
            'currency_id' => $note->currency_id,
            'status' => $status,
            'credit_date' => $note->credit_date?->toDateString(),
            'exchange_rate' => (string) $note->exchange_rate,
            'subtotal' => (string) $note->subtotal,
            'discount_total' => (string) $note->discount_total,
            'tax_total' => (string) $note->tax_total,
            'grand_total' => (string) $note->grand_total,
            'notes' => $note->notes,
            'customer' => $note->customer === null ? null : [
                'id' => $note->customer->id,
                'customer_code' => $note->customer->customer_code,
                'name' => $note->customer->name,
            ],
            'warehouse' => $note->warehouse === null ? null : [
                'id' => $note->warehouse->id,
                'name' => $note->warehouse->name,
                'shortcut_name' => $note->warehouse->shortcut_name,
            ],
            'currency' => $note->currency === null ? null : [
                'id' => $note->currency->id,
                'code' => $note->currency->code,
                'name' => $note->currency->name,
                'symbol' => $note->currency->symbol,
            ],
            'sales_invoice' => self::invoiceBrief($invoice),
            'created_by' => $note->createdByUser === null ? null : [
                'id' => $note->createdByUser->id,
                'name' => $note->createdByUser->name,
            ],
            'posted_by' => $note->postedByUser === null ? null : [
                'id' => $note->postedByUser->id,
                'name' => $note->postedByUser->name,
            ],
            'posted_at' => $note->posted_at?->toIso8601String(),
            'can_reverse' => $status === SalesCreditNoteStatus::Posted->value
                && ! self::invoiceRevokeRequested($note),
            'lines_count' => $note->lines_count ?? null,
            'created_at' => (string) $note->created_at,
            'updated_at' => (string) $note->updated_at,
        ];

        if ($includeLines) {
            $note->loadMissing(['lines.item', 'lines.itemUom.uom', 'lines.warehouse', 'lines.lot']);
            $payload['lines'] = SalesCreditNoteLineResponseData::collectionToArray($note->lines);
        }

        return $payload;
    }

    /**
     * @return array{id: string, invoice_number: ?string, status: ?string}|null
     */
    private static function invoiceBrief(?SalesInvoice $invoice): ?array
    {
        if ($invoice === null) {
            return null;
        }

        $status = $invoice->status instanceof SalesInvoiceStatus
            ? $invoice->status->value
            : (is_string($invoice->status) ? $invoice->status : null);

        return [
            'id' => (string) $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $status,
        ];
    }

    private static function invoiceRevokeRequested(SalesCreditNote $note): bool
    {
        return InvoiceChainRegistration::query()
            ->where('invoice_type', InvoiceProofType::Sales)
            ->where('invoice_id', $note->sales_invoice_id)
            ->whereNotNull('revoke_requested_at')
            ->exists();
    }
}
