<?php

namespace App\Modules\Inventory\Item\Models;

use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ItemUnitOfMeasurement extends Model
{
    protected $table = 'item_unit_of_measurement';

    /** @var list<string> */
    protected $fillable = [
        'item_id', 'unit_of_measurement_id', 'operation', 'conversion',
        'price_1', 'price_2', 'price_3', 'price_4', 'price_5', 'price_6',
        'gross_volume', 'gross_weight', 'net_volume', 'net_weight',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion' => 'decimal:6',
            'price_1' => 'decimal:2',
            'price_2' => 'decimal:2',
            'price_3' => 'decimal:2',
            'price_4' => 'decimal:2',
            'price_5' => 'decimal:2',
            'price_6' => 'decimal:2',
            'gross_volume' => 'decimal:6',
            'gross_weight' => 'decimal:6',
            'net_volume' => 'decimal:6',
            'net_weight' => 'decimal:6',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasurement, $this>
     */
    public function unitOfMeasurement(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasurement::class);
    }

    /**
     * @return HasMany<ItemBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'item_unit_of_measurement_id');
    }
}
