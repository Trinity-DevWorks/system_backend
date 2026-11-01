<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ItemVariant\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $sort_order
 */
#[Fillable(['code', 'name', 'sort_order'])]
class ItemAttribute extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return HasMany<ItemAttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(ItemAttributeValue::class)->orderBy('sort_order')->orderBy('id');
    }
}
