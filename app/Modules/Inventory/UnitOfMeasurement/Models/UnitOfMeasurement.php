<?php

namespace App\Modules\Inventory\UnitOfMeasurement\Models;

use App\Modules\Inventory\UnitGroup\Models\UnitGroup;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['unit_group_id', 'code', 'name', 'symbol', 'decimal_places', 'is_active'])]
class UnitOfMeasurement extends Model implements AuditableContract
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
            'decimal_places' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<UnitGroup, $this>
     */
    public function unitGroup(): BelongsTo
    {
        return $this->belongsTo(UnitGroup::class);
    }
}
