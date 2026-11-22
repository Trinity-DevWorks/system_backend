<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Models;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $run_id
 * @property string $fixed_asset_id
 * @property string $amount
 * @property string $book_value_after
 * @property Carbon|null $through_before
 */
#[Fillable([
    'run_id', 'fixed_asset_id', 'branch_id', 'amount', 'book_value_after', 'through_before',
])]
class DepreciationLine extends Model
{
    protected $table = 'fixed_asset_depreciation_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'book_value_after' => 'decimal:4',
            'through_before' => 'date',
        ];
    }

    /**
     * @return BelongsTo<DepreciationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<FixedAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
