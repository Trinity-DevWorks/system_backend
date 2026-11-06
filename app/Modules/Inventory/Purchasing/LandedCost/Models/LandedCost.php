<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Models;

use App\Models\User;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Inventory\Purchasing\LandedCost\Enums\LandedCostStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One shipment's extra costs (freight, customs, agent) spread over the goods it brought in.
 *
 * @property string $id
 * @property int $branch_id
 * @property string|null $landed_cost_number
 * @property Carbon $cost_date
 * @property string|null $reference
 * @property string|null $notes
 * @property LandedCostStatus $status
 * @property string|null $created_by
 * @property string|null $posted_by
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'branch_id', 'landed_cost_number', 'cost_date', 'reference', 'notes', 'status', 'created_by', 'posted_by', 'posted_at',
])]
class LandedCost extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'landed_cost';

    /**
     * No warehouse on the header: the branch comes from the user's active branch.
     *
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return ['branch_id' => null];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LandedCostStatus::class,
            'cost_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<LandedCostReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(LandedCostReceipt::class)->orderBy('id');
    }

    /**
     * @return HasMany<LandedCostCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(LandedCostCharge::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
