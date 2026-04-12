<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'supplier_id',
    'name',
    'phone',
    'email',
    'position',
])]
class SupplierContact extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
