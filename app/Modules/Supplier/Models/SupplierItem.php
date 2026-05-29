<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use App\Modules\Currency\Models\Currency;
use App\Modules\Inventory\Item\Models\Item;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'supplier_id',
    'item_id',
    'supplier_sku',
    'last_purchase_price',
    'currency_id',
    'lead_time_days',
    'is_preferred',
])]
class SupplierItem extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_purchase_price' => 'decimal:4',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }
}
