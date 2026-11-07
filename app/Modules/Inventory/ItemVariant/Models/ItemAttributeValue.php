<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ItemVariant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property int $item_attribute_id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 */
#[Fillable(['item_attribute_id', 'code', 'name', 'sort_order'])]
class ItemAttributeValue extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return BelongsTo<ItemAttribute, $this>
     */
    public function attribute(): BelongsTo
    {
        return $this->belongsTo(ItemAttribute::class, 'item_attribute_id');
    }
}
