<?php

namespace App\Modules\Inventory\Item\Models;

use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['code', 'name', 'type', 'base_uom_id', 'purchase_uom_id', 'sales_uom_id', 'active'])]
class Item extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function baseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'base_uom_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'purchase_uom_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function salesUom(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'sales_uom_id');
    }

    /**
     * @return HasMany<ItemUnitOfMeasurement, $this>
     */
    public function itemUnitOfMeasurements(): HasMany
    {
        return $this->hasMany(ItemUnitOfMeasurement::class, 'item_id');
    }

    /**
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class);
    }
}
