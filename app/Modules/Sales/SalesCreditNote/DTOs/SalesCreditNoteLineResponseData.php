<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\DTOs;

use App\Modules\Sales\SalesCreditNote\Models\SalesCreditNoteLine;
use Illuminate\Support\Collection;

readonly class SalesCreditNoteLineResponseData
{
    public static function fromModel(SalesCreditNoteLine $line): array
    {
        $line->loadMissing([
            'item:id,item_code,name,description',
            'itemUom:id,uom_id,conversion_factor',
            'itemUom.uom:id,code,name',
            'warehouse:id,name,shortcut_name',
            'lot:id,lot_number,expiry_date',
        ]);

        return [
            'id' => $line->id,
            'sales_credit_note_id' => $line->sales_credit_note_id,
            'sales_invoice_line_id' => $line->sales_invoice_line_id,
            'item_id' => $line->item_id,
            'item_uom_id' => $line->item_uom_id,
            'warehouse_id' => $line->warehouse_id,
            'lot_id' => $line->lot_id,
            'sort_order' => (int) $line->sort_order,
            'quantity' => (string) $line->quantity,
            'base_quantity' => (string) $line->base_quantity,
            'conversion_factor' => (string) $line->conversion_factor,
            'unit_price' => (string) $line->unit_price,
            'discount_percent' => (string) $line->discount_percent,
            'discount_amount' => (string) $line->discount_amount,
            'tax_rate' => (string) $line->tax_rate,
            'line_subtotal' => (string) $line->line_subtotal,
            'tax_amount' => (string) $line->tax_amount,
            'line_total' => (string) $line->line_total,
            'description' => $line->description,
            'notes' => $line->notes,
            'item' => $line->item === null ? null : [
                'id' => $line->item->id,
                'item_code' => $line->item->item_code,
                'name' => $line->item->name,
            ],
            'uom' => $line->itemUom?->uom === null ? null : [
                'id' => $line->itemUom->uom->id,
                'code' => $line->itemUom->uom->code,
                'name' => $line->itemUom->uom->name,
            ],
            'lot' => $line->lot === null ? null : [
                'id' => $line->lot->id,
                'lot_number' => $line->lot->lot_number,
            ],
        ];
    }

    /**
     * @param  Collection<int, SalesCreditNoteLine>  $lines
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $lines): array
    {
        return $lines->map(fn (SalesCreditNoteLine $line): array => self::fromModel($line))->values()->all();
    }
}
