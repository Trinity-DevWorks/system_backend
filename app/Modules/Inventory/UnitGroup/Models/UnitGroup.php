<?php

namespace App\Modules\Inventory\UnitGroup\Models;

use App\Modules\Inventory\Shared\Enums\DimensionType;
use App\Modules\Inventory\UnitOfMeasurement\Models\UnitOfMeasurement;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['code', 'name', 'dimension_type', 'is_active'])]
class UnitGroup extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'dimension_type' => DimensionType::class,
        ];
    }

    /**
     * @return HasMany<UnitOfMeasurement, $this>
     */
    public function unitsOfMeasurement(): HasMany
    {
        return $this->hasMany(UnitOfMeasurement::class);
    }
}
