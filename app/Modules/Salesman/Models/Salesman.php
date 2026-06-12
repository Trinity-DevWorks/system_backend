<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Models;

use App\Models\Attachment;
use App\Models\User;
use App\Modules\Salesman\Enums\CommissionType;
use App\Modules\Warehouse\Models\Warehouse;
use Database\Factories\SalesmanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'salesman_code',
    'first_name',
    'last_name',
    'full_name',
    'phone',
    'email',
    'address',
    'commission_type',
    'commission_value',
    'target_amount',
    'hire_date',
    'warehouse_id',
    'user_id',
    'is_active',
    'notes',
])]
class Salesman extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<SalesmanFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'salesmen';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_type' => CommissionType::class,
            'commission_value' => 'decimal:4',
            'target_amount' => 'decimal:4',
            'hire_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphMany<Attachment, $this>
     */
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
