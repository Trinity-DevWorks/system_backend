<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Models;

use App\Models\User;
use App\Modules\Accounting\FixedAsset\Enums\DepreciationRunStatus;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One month of depreciation, posted as a journal entry per branch.
 *
 * @property int $branch_id
 * @property Carbon $period_month
 * @property DepreciationRunStatus $status
 * @property string $total
 * @property string|null $run_number
 * @property bool $is_automatic
 * @property Carbon|null $posted_at
 */
#[Fillable([
    'branch_id', 'period_month', 'run_number', 'status', 'total', 'is_automatic', 'posted_by', 'posted_at',
])]
class DepreciationRun extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    public const REFERENCE_TYPE = 'fixed_asset_depreciation';

    protected $table = 'fixed_asset_depreciation_runs';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'status' => DepreciationRunStatus::class,
            'total' => 'decimal:4',
            'is_automatic' => 'boolean',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<DepreciationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class, 'run_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
