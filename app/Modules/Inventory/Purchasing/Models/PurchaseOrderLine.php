<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Models;

use App\Modules\CompanySetting\Support\PriceMath;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Models\ItemUom;
use App\Support\ExchangeRateSnapshot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property string|null $unit_price
 * @property string $discount_percent
 * @property string $discount_amount
 * @property string $tax_rate
 * @property string $line_subtotal
 * @property string $tax_amount
 * @property string $line_total
 */
#[Fillable([
    'purchase_order_id',
    'item_id',
    'quantity',
    'base_quantity',
    'received_quantity',
    'received_base_quantity',
    'item_uom_id',
    'unit_price',
    'discount_percent',
    'discount_amount',
    'tax_rate',
    'line_subtotal',
    'tax_amount',
    'line_total',
    'notes',
])]
class PurchaseOrderLine extends Model implements AuditableContract
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
            'received_quantity' => 'decimal:6',
            'received_base_quantity' => 'decimal:6',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_rate' => 'decimal:4',
            'line_subtotal' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
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
     * Default goods receipt cost for this line, in the primary currency: the net unit price
     * (after discount, before VAT) converted at the order's exchange rate (docs/printing-plan.md D1.3).
     */
    public function primaryUnitCost(?string $orderExchangeRate = null): ?string
    {
        if ($this->unit_price === null || $this->unit_price === '') {
            return null;
        }

        $quantity = (string) $this->quantity;
        $net = bccomp($quantity, '0', 6) > 0
            ? bcdiv((string) $this->line_subtotal, $quantity, 8)
            : (string) $this->unit_price;

        $rate = $orderExchangeRate ?? ($this->relationLoaded('purchaseOrder')
            ? $this->purchaseOrder?->exchange_rate
            : PurchaseOrder::query()->whereKey($this->purchase_order_id)->value('exchange_rate'));

        return PriceMath::normalize(ExchangeRateSnapshot::toPrimary($net, (string) ($rate ?? '1'), 8));
    }
}
