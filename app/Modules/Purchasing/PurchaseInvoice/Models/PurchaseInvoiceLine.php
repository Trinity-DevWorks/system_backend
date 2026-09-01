<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\PurchaseInvoice\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Modules\Inventory\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Inventory\Purchasing\Models\PurchaseOrderLine;
use App\Modules\VatGroup\Models\VatGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'purchase_invoice_id',
    'item_id',
    'purchase_order_line_id',
    'goods_receipt_line_id',
    'quantity',
    'base_quantity',
    'item_uom_id',
    'unit_price',
    'vat_group_id',
    'tax_rate',
    'line_subtotal',
    'tax_amount',
    'line_total',
    'notes',
])]
class PurchaseInvoiceLine extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'tax_rate' => 'decimal:2',
            'line_subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PurchaseInvoice, $this>
     */
    public function purchaseInvoice(): BelongsTo
    {
        return $this->belongsTo(PurchaseInvoice::class);
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
     * @return BelongsTo<VatGroup, $this>
     */
    public function vatGroup(): BelongsTo
    {
        return $this->belongsTo(VatGroup::class);
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    /**
     * @return BelongsTo<GoodsReceiptLine, $this>
     */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }
}
