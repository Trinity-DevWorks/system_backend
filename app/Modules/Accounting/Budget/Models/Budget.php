<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Models;

use App\Models\User;
use App\Modules\Accounting\Budget\Enums\BudgetStatus;
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
 * @property string $id
 * @property string $name
 * @property Carbon $fiscal_year_start
 * @property Carbon $fiscal_year_end
 * @property int|null $branch_id
 * @property int $version
 * @property string|null $parent_budget_id
 * @property BudgetStatus $status
 * @property string|null $notes
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $created_by
 */
#[Fillable([
    'name',
    'fiscal_year_start',
    'fiscal_year_end',
    'branch_id',
    'version',
    'parent_budget_id',
    'status',
    'notes',
    'approved_by',
    'approved_at',
    'created_by',
])]
class Budget extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year_start' => 'date',
            'fiscal_year_end' => 'date',
            'branch_id' => 'integer',
            'version' => 'integer',
            'status' => BudgetStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<BudgetLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function parentBudget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_budget_id');
    }

    /**
     * @return HasMany<Budget, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_budget_id');
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
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === BudgetStatus::Draft;
    }
}
