<?php

namespace App\Modules\Brand\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable(['code', 'name', 'parent_brand_id', 'is_active'])]
class Brand extends Model implements AuditableContract
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
        ];
    }

    public function parentBrand(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_brand_id');
    }

    public function subBrands(): HasMany
    {
        return $this->hasMany(self::class, 'parent_brand_id');
    }
}
