<?php

declare(strict_types=1);

namespace App\Modules\Sales\DeliveryNote\DTOs;

use App\Modules\Sales\DeliveryNote\Models\DeliveryNote;
use App\Modules\Sales\DeliveryNote\Models\DeliveryNoteLine;

final class DeliveryNoteResponseData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromModel(DeliveryNote $note): array
    {
        $lines = $note->relationLoaded('lines') ? $note->lines : collect();
        $invoicedAll = $lines->isNotEmpty() && $lines->every(fn (DeliveryNoteLine $l): bool => bccomp($l->uninvoicedBaseQuantity(), '0', 6) === 0);
        $invoicedAny = $lines->contains(fn (DeliveryNoteLine $l): bool => bccomp((string) $l->invoiced_base_quantity, '0', 6) > 0);

        return [
            'id' => (string) $note->id,
            'branch_id' => (int) $note->branch_id,
            'delivery_number' => $note->delivery_number,
            'delivery_date' => $note->delivery_date->toDateString(),
            'status' => $note->status->value,
            'invoicing_status' => $invoicedAll ? 'invoiced' : ($invoicedAny ? 'partially_invoiced' : 'not_invoiced'),
            'customer_id' => (string) $note->customer_id,
            'customer' => $note->relationLoaded('customer') && $note->customer !== null
                ? ['id' => (string) $note->customer->id, 'name' => $note->customer->name, 'customer_code' => $note->customer->getAttribute('customer_code')]
                : null,
            'warehouse_id' => (int) $note->warehouse_id,
            'warehouse' => $note->relationLoaded('warehouse') && $note->warehouse !== null
                ? ['id' => (int) $note->warehouse->id, 'name' => $note->warehouse->name]
                : null,
            'sales_order_id' => $note->sales_order_id,
            'sales_order' => $note->relationLoaded('salesOrder') && $note->salesOrder !== null
                ? ['id' => (string) $note->salesOrder->id, 'order_number' => $note->salesOrder->getAttribute('order_number')]
                : null,
            'shipping_address' => $note->shipping_address,
            'notes' => $note->notes,
            'posted_at' => $note->posted_at?->toIso8601String(),
            'posted_by' => $note->relationLoaded('postedByUser') && $note->postedByUser !== null
                ? ['id' => (string) $note->postedByUser->id, 'name' => $note->postedByUser->name]
                : null,
            'lines' => $lines->map(fn (DeliveryNoteLine $l): array => [
                'id' => (int) $l->id,
                'sales_order_line_id' => $l->sales_order_line_id,
                'item_id' => (string) $l->item_id,
                'item' => $l->relationLoaded('item') && $l->item !== null
                    ? ['id' => (string) $l->item->id, 'item_code' => $l->item->item_code, 'name' => $l->item->name, 'track_lots' => (bool) $l->item->track_lots]
                    : null,
                'item_uom_id' => $l->item_uom_id,
                'uom' => $l->relationLoaded('itemUom') && $l->itemUom?->uom !== null
                    ? ['code' => $l->itemUom->uom->code, 'name' => $l->itemUom->uom->name]
                    : null,
                'warehouse_id' => $l->warehouse_id,
                'lot_id' => $l->lot_id,
                'lot' => $l->relationLoaded('lot') && $l->lot !== null
                    ? ['id' => (int) $l->lot->id, 'lot_number' => $l->lot->lot_number, 'expiry_date' => $l->lot->expiry_date?->toDateString()]
                    : null,
                'quantity' => (string) $l->quantity,
                'base_quantity' => (string) $l->base_quantity,
                'conversion_factor' => (string) $l->conversion_factor,
                'invoiced_base_quantity' => (string) $l->invoiced_base_quantity,
                'uninvoiced_quantity' => $l->uninvoicedQuantity(),
                'description' => $l->description,
                'notes' => $l->notes,
            ])->values()->all(),
            'created_at' => (string) $note->created_at,
            'updated_at' => (string) $note->updated_at,
        ];
    }
}
