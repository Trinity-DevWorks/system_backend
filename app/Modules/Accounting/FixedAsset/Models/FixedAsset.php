<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Models;

use App\Models\User;
use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use App\Modules\Accounting\FixedAsset\Enums\FixedAssetSource;
use App\Modules\Accounting\FixedAsset\Enums\FixedAssetStatus;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * @property int $branch_id
 * @property string|null $asset_number
 * @property FixedAssetStatus $status
 * @property FixedAssetSource $source_type
 * @property DepreciationMethod $method
 * @property Carbon $acquisition_date
 * @property Carbon $in_service_date
 * @property Carbon|null $depreciated_through
 * @property Carbon|null $disposed_at
 * @property Carbon|null $disposal_prior_through
 * @property string $cost
 * @property string $salvage_value
 * @property string $opening_accumulated
 * @property string $accumulated_depreciation
 * @property string|null $declining_rate
 * @property string|null $disposal_amount
 * @property string $disposal_catch_up
 * @property Carbon|null $posted_at
 * @property string|null $disposal_entry_id
 */
#[Fillable([
    'branch_id', 'asset_number', 'name', 'category_id', 'item_id',
    'source_type', 'source_id', 'supplier_id',
    'serial_number', 'location', 'custodian',
    'acquisition_date', 'in_service_date', 'cost', 'salvage_value',
    'method', 'useful_life_months', 'declining_rate', 'prorate_first_month',
    'opening_accumulated', 'accumulated_depreciation', 'depreciated_through',
    'status', 'disposed_at', 'disposal_amount', 'disposal_catch_up', 'disposal_prior_through',
    'disposal_number', 'disposal_entry_id', 'created_by', 'posted_by', 'posted_at',
])]
class FixedAsset extends Model implements AuditableContract
{
    use Auditable;
    use HasDocumentBranch;
    use HasUuids;

    public const REFERENCE_TYPE = 'fixed_asset';

    public const DISPOSAL_REFERENCE = 'fixed_asset_disposal';

    /**
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
            'status' => FixedAssetStatus::class,
            'source_type' => FixedAssetSource::class,
            'method' => DepreciationMethod::class,
            'acquisition_date' => 'date',
            'in_service_date' => 'date',
            'depreciated_through' => 'date',
            'disposed_at' => 'date',
            'disposal_prior_through' => 'date',
            'useful_life_months' => 'integer',
            'prorate_first_month' => 'boolean',
            'cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'declining_rate' => 'decimal:4',
            'opening_accumulated' => 'decimal:4',
            'accumulated_depreciation' => 'decimal:4',
            'disposal_amount' => 'decimal:4',
            'disposal_catch_up' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FixedAssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function disposalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'disposal_entry_id');
    }

    /**
     * @return HasMany<DepreciationLine, $this>
     */
    public function depreciationLines(): HasMany
    {
        return $this->hasMany(DepreciationLine::class);
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
