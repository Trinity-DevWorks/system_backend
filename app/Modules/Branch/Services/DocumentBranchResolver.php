<?php

declare(strict_types=1);

namespace App\Modules\Branch\Services;

use App\Models\User;
use App\Modules\Branch\Support\HasDocumentBranch;
use App\Modules\Warehouse\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Sets and guards the issuing branch of business documents (docs/printing-plan.md, D3).
 *
 * Default, in order: the branch of a branch-owned warehouse, the source document's branch,
 * the user's active branch, the company default branch. A branch-owned warehouse must belong to
 * the document's branch, the user must be allowed to work in it, and it cannot change after draft.
 */
class DocumentBranchResolver
{
    public function __construct(
        private readonly BranchContextService $context,
        private readonly BranchService $branches,
    ) {}

    public function creating(Model $document): void
    {
        foreach ($this->columns($document) as $branchColumn => $warehouseColumn) {
            $warehouseBranch = $this->warehouseBranchId($document, $warehouseColumn);
            $current = $document->getAttribute($branchColumn);

            if ($current === null || $current === '') {
                $document->setAttribute($branchColumn, $warehouseBranch ?? $this->fallback($document));
            }

            $this->assertValid($document, $branchColumn, $warehouseBranch);
        }
    }

    public function updating(Model $document): void
    {
        foreach ($this->columns($document) as $branchColumn => $warehouseColumn) {
            $branchChanged = $document->isDirty($branchColumn);
            $warehouseChanged = $warehouseColumn !== null && $document->isDirty($warehouseColumn);
            if (! $branchChanged && ! $warehouseChanged) {
                continue;
            }

            $warehouseBranch = $this->warehouseBranchId($document, $warehouseColumn);
            if (! $branchChanged && $warehouseBranch !== null) {
                $document->setAttribute($branchColumn, $warehouseBranch);
            }

            if ($document->isDirty($branchColumn) && $this->isFrozen($document)) {
                abort(422, 'The branch of a posted document cannot change.', [
                    'X-Error-Code' => 'DOCUMENT_BRANCH_LOCKED',
                ]);
            }

            $this->assertValid($document, $branchColumn, $warehouseBranch);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function columns(Model $document): array
    {
        if (! in_array(HasDocumentBranch::class, class_uses_recursive($document), true)
            || ! method_exists($document, 'documentBranchColumns')) {
            return [];
        }

        /** @var array<string, string|null> $columns */
        $columns = $document->documentBranchColumns();

        return $columns;
    }

    private function isFrozen(Model $document): bool
    {
        return method_exists($document, 'documentBranchIsFrozen') && (bool) $document->documentBranchIsFrozen();
    }

    private function warehouseBranchId(Model $document, ?string $warehouseColumn): ?int
    {
        if ($warehouseColumn === null) {
            return null;
        }

        $warehouseId = $document->getAttribute($warehouseColumn);
        if ($warehouseId === null || $warehouseId === '') {
            return null;
        }

        $branchId = Warehouse::query()->whereKey((int) $warehouseId)->value('branch_id');

        return $branchId !== null ? (int) $branchId : null;
    }

    private function fallback(Model $document): int
    {
        $parent = method_exists($document, 'documentBranchFromParent') ? $document->documentBranchFromParent() : null;
        if ($parent !== null) {
            return (int) $parent;
        }

        $user = $this->user();
        $active = $user !== null ? $this->context->resolveActiveBranchId($user) : null;

        return $active ?? $this->branches->defaultBranchId();
    }

    private function assertValid(Model $document, string $branchColumn, ?int $warehouseBranch): void
    {
        $branchId = (int) $document->getAttribute($branchColumn);

        if ($warehouseBranch !== null && $warehouseBranch !== $branchId) {
            abort(422, 'The warehouse belongs to another branch.', [
                'X-Error-Code' => 'DOCUMENT_BRANCH_WAREHOUSE_MISMATCH',
            ]);
        }

        $user = $this->user();
        if ($user !== null && ! $this->context->canAccessBranch($branchId, $user)) {
            abort(403, 'You cannot work in this branch.', [
                'X-Error-Code' => 'DOCUMENT_BRANCH_NOT_ACCESSIBLE',
            ]);
        }
    }

    private function user(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
