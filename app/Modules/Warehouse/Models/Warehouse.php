<?php

declare(strict_types=1);

namespace App\Modules\Warehouse\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use App\Modules\Warehouse\Enums\WarehouseType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'name',
    'shortcut_name',
    'type',
    'branch_id',
    'address',
    'description',
    'manager_id',
    'is_active',
    'is_default',
    'is_default_sales',
    'is_default_production',
    'is_default_purchase',
    'is_default_storage',
])]
class Warehouse extends Model implements AuditableContract
{
    use Auditable;
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WarehouseType::class,
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'is_default_sales' => 'boolean',
            'is_default_production' => 'boolean',
            'is_default_purchase' => 'boolean',
            'is_default_storage' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
