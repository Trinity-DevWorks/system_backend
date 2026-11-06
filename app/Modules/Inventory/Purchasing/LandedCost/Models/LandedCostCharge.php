<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Models;

use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bill line (purchase invoice line or expense voucher line) taken into a landed cost.
 * Amount is in the primary currency and may be part of the bill line (the rest stays open).
 *
 * @property int $id
 * @property string $landed_cost_id
 * @property string $source_type purchase_invoice_line | expense_voucher_line
 * @property int $source_line_id
 * @property string $amount
 * @property LandedCostMethod $method
 * @property string|null $description
 * @property int $sort_order
 */
#[Fillable(['landed_cost_id', 'source_type', 'source_line_id', 'amount', 'method', 'description', 'sort_order'])]
class LandedCostCharge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => LandedCostMethod::class,
            'source_line_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LandedCost, $this>
     */
    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    /**
     * @return HasMany<LandedCostAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class)->orderBy('id');
    }
}
