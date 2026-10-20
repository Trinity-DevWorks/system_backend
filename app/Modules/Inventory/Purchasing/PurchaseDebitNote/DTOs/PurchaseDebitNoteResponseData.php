<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\PurchaseDebitNote\DTOs;

use App\Models\User;
use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteStatus;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Enums\PurchaseDebitNoteType;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNoteAllocation;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNoteLine;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Collection;

readonly class PurchaseDebitNoteResponseData
{
    /**
     * @param  list<array<string, mixed>>  $applicableInvoices
     * @return array<string, mixed>
     */
    public static function fromModel(PurchaseDebitNote $note, bool $includeLines = true, array $applicableInvoices = []): array
    {
        $note->loadMissing([
            'supplier:id,supplier_code,name,phone,is_active',
            'currency:id,code,name,symbol',
            'purchaseInvoice:id,invoice_number,invoice_date,status,grand_total,supplier_id,currency_id',
            'createdByUser:id,name,email',
            'postedByUser:id,name,email',
        ]);

        $payload = [
            'id' => $note->id,
            'debit_note_number' => $note->debit_note_number,
            'purchase_invoice_id' => $note->purchase_invoice_id,
            'supplier_id' => $note->supplier_id,
            'currency_id' => $note->currency_id,
            'exchange_rate' => (string) $note->exchange_rate,
            'type' => $note->type instanceof PurchaseDebitNoteType ? $note->type->value : (string) $note->type,
            'debit_date' => $note->debit_date?->toDateString(),
            'reason' => $note->reason,
            'notes' => $note->notes,
            'subtotal' => (string) $note->subtotal,
            'discount_total' => (string) $note->discount_total,
            'tax_total' => (string) $note->tax_total,
            'adjustment' => (string) $note->adjustment,
            'grand_total' => (string) $note->grand_total,
            'applied_total' => (string) $note->applied_total,
            'refunded_total' => (string) $note->refunded_total,
            'open_credit' => (string) $note->open_credit,
            'status' => $note->status instanceof PurchaseDebitNoteStatus ? $note->status->value : (string) $note->status,
            'supplier' => self::supplierBrief($note->supplier),
            'currency' => self::currencyBrief($note->currency),
            'purchase_invoice' => $note->purchaseInvoice ? [
                'id' => $note->purchaseInvoice->id,
                'invoice_number' => $note->purchaseInvoice->invoice_number,
                'invoice_date' => $note->purchaseInvoice->invoice_date?->toDateString(),
                'grand_total' => (string) $note->purchaseInvoice->grand_total,
            ] : null,
            'created_by' => self::userBrief($note->createdByUser),
            'posted_by' => self::userBrief($note->postedByUser),
            'posted_at' => $note->posted_at?->toIso8601String(),
            'lines_count' => $note->lines_count ?? null,
            'created_at' => (string) $note->created_at,
            'updated_at' => (string) $note->updated_at,
            'applicable_invoices' => $applicableInvoices,
        ];

        if ($includeLines) {
            $note->loadMissing([
                'lines.item',
                'lines.itemUom.uom',
                'lines.warehouse',
                'lines.lot',
                'allocations.purchaseInvoice:id,invoice_number,invoice_date,grand_total,net_to_pay',
            ]);
            $payload['lines'] = self::linesToArray($note->lines);
            $payload['allocations'] = self::allocationsToArray($note->allocations);
        }

        return $payload;
    }

    /**
     * @param  Collection<int, PurchaseDebitNoteLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function linesToArray(Collection $lines): array
    {
        return $lines->map(fn (PurchaseDebitNoteLine $line): array => [
            'id' => $line->id,
            'purchase_invoice_line_id' => $line->purchase_invoice_line_id,
            'item_id' => $line->item_id,
            'item_uom_id' => $line->item_uom_id,
            'warehouse_id' => $line->warehouse_id,
            'lot_id' => $line->lot_id,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'conversion_factor' => (string) $line->conversion_factor,
            'unit_price' => (string) $line->unit_price,
            'discount_percent' => (string) $line->discount_percent,
            'tax_rate' => (string) $line->tax_rate,
            'line_subtotal' => (string) $line->line_subtotal,
            'discount_amount' => (string) $line->discount_amount,
            'tax_amount' => (string) $line->tax_amount,
            'line_total' => (string) $line->line_total,
            'description' => $line->description,
            'sort_order' => $line->sort_order,
            'item' => $line->item ? [
                'id' => $line->item->id,
                'name' => $line->item->name,
                'item_code' => $line->item->item_code,
                'track_inventory' => (bool) $line->item->track_inventory,
            ] : null,
            'warehouse' => $line->warehouse ? [
                'id' => $line->warehouse->id,
                'name' => $line->warehouse->name,
            ] : null,
        ])->values()->all();
    }

    /**
     * @param  Collection<int, PurchaseDebitNoteAllocation>  $allocations
     * @return list<array<string, mixed>>
     */
    public static function allocationsToArray(Collection $allocations): array
    {
        return $allocations->map(function (PurchaseDebitNoteAllocation $allocation): array {
            $invoice = $allocation->purchaseInvoice;

            return [
                'id' => $allocation->id,
                'purchase_invoice_id' => $allocation->purchase_invoice_id,
                'amount' => (string) $allocation->amount,
                'is_automatic' => (bool) $allocation->is_automatic,
                'invoice_number' => $invoice?->invoice_number,
                'invoice_date' => $invoice instanceof PurchaseInvoice ? $invoice->invoice_date?->toDateString() : null,
                'grand_total' => $invoice ? (string) $invoice->grand_total : null,
                'net_to_pay' => $invoice ? (string) $invoice->net_to_pay : null,
            ];
        })->values()->all();
    }

    /**
     * @return array{id:string,name:?string,supplier_code:?string}|null
     */
    private static function supplierBrief(?Supplier $customer): ?array
    {
        if (! $customer) {
            return null;
        }

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'supplier_code' => $customer->supplier_code,
        ];
    }

    /**
     * @return array{id:int,code:?string,name:?string,symbol:?string}|null
     */
    private static function currencyBrief(?Currency $currency): ?array
    {
        if (! $currency) {
            return null;
        }

        return [
            'id' => $currency->id,
            'code' => $currency->code,
            'name' => $currency->name,
            'symbol' => $currency->symbol,
        ];
    }

    /**
     * @return array{id:string,name:?string,email:?string}|null
     */
    private static function userBrief(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}
