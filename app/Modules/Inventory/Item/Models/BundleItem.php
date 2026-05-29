<?php

namespace App\Modules\Inventory\Item\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['bundle_item_id', 'child_item_id', 'quantity'])]
class BundleItem extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
        ];
    }

    public function bundleItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'bundle_item_id');
    }

    public function childItem(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'child_item_id');
    }
}
