<?php

declare(strict_types=1);

namespace App\Modules\Sales\SalesOrder\Models;

use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string|null $invoiced_base_quantity
 */
#[Fillable([
    'sales_order_id',
    'item_id',
    'item_uom_id',
    'sort_order',
    'quantity',
    'base_quantity',
    'invoiced_base_quantity',
    'conversion_factor',
    'unit_price',
    'discount_percent',
    'discount_amount',
    'tax_rate',
    'line_subtotal',
    'tax_amount',
    'line_total',
    'description',
    'notes',
])]
class SalesOrderLine extends Model implements AuditableContract
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
            'invoiced_base_quantity' => 'decimal:6',
            'conversion_factor' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SalesOrder, $this>
     */
    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
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

    public function remainingBaseQuantity(): string
    {
        $remaining = bcsub((string) $this->base_quantity, (string) $this->invoiced_base_quantity, 6);

        return bccomp($remaining, '0', 6) < 0 ? '0.000000' : $remaining;
    }

    public function remainingQuantity(): string
    {
        $factor = (string) $this->conversion_factor;
        if (bccomp($factor, '0', 6) <= 0) {
            $factor = '1';
        }

        return bcdiv($this->remainingBaseQuantity(), $factor, 6);
    }
}
