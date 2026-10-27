<?php

declare(strict_types=1);

namespace App\Modules\Branch\Support;

use App\Modules\Branch\Models\Branch;
use App\Modules\Branch\Services\DocumentBranchResolver;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business document that records the branch that issued it.
 *
 * The branch is stored on the document (not looked up from the warehouse later), so reprints,
 * journal entries and branch reports never move when a warehouse is re-assigned.
 * See docs/printing-plan.md, decision D3.
 *
 * @mixin Model
 */
trait HasDocumentBranch
{
    public static function bootHasDocumentBranch(): void
    {
        static::creating(function (Model $document): void {
            app(DocumentBranchResolver::class)->creating($document);
        });

        static::updating(function (Model $document): void {
            app(DocumentBranchResolver::class)->updating($document);
        });
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Branch column => the warehouse column that pins it (null when the document has no warehouse).
     *
     * @return array<string, string|null>
     */
    public function documentBranchColumns(): array
    {
        return ['branch_id' => 'warehouse_id'];
    }

    /**
     * Branch inherited from a source document (e.g. the invoice of a credit note), used before the user's active branch.
     */
    public function documentBranchFromParent(): ?int
    {
        return null;
    }

    /**
     * Branch stored on a source document, or null when there is no source.
     *
     * @param  class-string<Model>  $model
     */
    protected function branchOfDocument(string $model, mixed $key, string $column = 'branch_id'): ?int
    {
        if ($key === null || $key === '') {
            return null;
        }

        $branchId = $model::query()->whereKey($key)->value($column);

        return $branchId !== null ? (int) $branchId : null;
    }

    /**
     * The branch is editable only while the document is a draft.
     */
    public function documentBranchIsFrozen(): bool
    {
        $status = $this->getOriginal('status');
        $value = $status instanceof BackedEnum ? (string) $status->value : (string) $status;

        return $value !== 'draft';
    }
}
