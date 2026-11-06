<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A posted goods receipt or direct purchase invoice whose goods share the landed cost.
 *
 * @property int $id
 * @property string $landed_cost_id
 * @property string $source_type goods_receipt | purchase_invoice
 * @property string $source_id
 */
#[Fillable(['landed_cost_id', 'source_type', 'source_id'])]
class LandedCostReceipt extends Model
{
    /**
     * @return BelongsTo<LandedCost, $this>
     */
    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }
}
