<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Models;

use App\Models\User;
use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One downloaded scale file: the history, and the "last export" the out-of-date warning compares to.
 *
 * @property int $id
 * @property int $scale_export_template_id
 * @property int|null $branch_id
 * @property bool $only_changed
 * @property int $item_count
 * @property list<string> $item_ids
 * @property string $file_name
 * @property string|null $exported_by
 * @property Carbon $created_at
 */
#[Fillable(['scale_export_template_id', 'branch_id', 'only_changed', 'item_count', 'item_ids', 'file_name', 'exported_by', 'created_at'])]
class ScaleExport extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'only_changed' => 'boolean',
            'item_count' => 'integer',
            'item_ids' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ScaleExportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ScaleExportTemplate::class, 'scale_export_template_id');
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }
}
