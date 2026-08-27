<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Models;

use App\Modules\Inventory\Stock\Enums\StockAdjustmentReasonDirection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'code',
    'name',
    'direction',
    'is_active',
    'is_system',
    'notes',
])]
class StockAdjustmentReason extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => StockAdjustmentReasonDirection::class,
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StockAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(StockAdjustment::class);
    }
}
