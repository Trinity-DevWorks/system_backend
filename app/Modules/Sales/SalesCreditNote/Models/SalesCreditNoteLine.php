<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesCreditNote\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Stock\Models\InventoryLot;
use App\Modules\Sales\SalesInvoice\Models\SalesInvoiceLine;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sales_credit_note_id',
    'sales_invoice_line_id',
    'item_id',
    'item_uom_id',
    'warehouse_id',
    'lot_id',
    'quantity',
    'base_quantity',
    'conversion_factor',
    'unit_price',
    'discount_percent',
    'tax_rate',
    'line_subtotal',
    'discount_amount',
    'tax_amount',
    'line_total',
    'restock',
    'description',
    'sort_order',
])]
class SalesCreditNoteLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'restock' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesCreditNote, $this>
     */
    public function salesCreditNote(): BelongsTo
    {
        return $this->belongsTo(SalesCreditNote::class);
    }

    /**
     * @return BelongsTo<SalesInvoiceLine, $this>
     */
    public function salesInvoiceLine(): BelongsTo
    {
        return $this->belongsTo(SalesInvoiceLine::class);
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<ItemUom, $this>
     */
    public function itemUom(): BelongsTo
    {
        return $this->belongsTo(ItemUom::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<InventoryLot, $this>
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'lot_id');
    }
}
