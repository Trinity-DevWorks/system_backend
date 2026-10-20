<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Models\User;
use App\Modules\Branch\Services\BranchContextService;

/**
 * Keeps a financial report inside the branches the current user may see.
 * The owner may see the whole company. Anyone else is limited to the
 * branches they are assigned to, and a single assignment is selected for them.
 */
final class ReportBranchScope
{
    public function __construct(
        private readonly BranchContextService $branches,
    ) {}

    /**
     * @return array{branch_id: int|null, filters: array<string, mixed>}
     */
    public function apply(?int $requested): array
    {
        $user = request()->user();
        if (! $user instanceof User || $this->branches->isOwner($user)) {
            return [
                'branch_id' => $requested,
                'filters' => $requested !== null ? ['branch_id' => $requested] : [],
            ];
        }

        $allowed = $this->branches->accessibleBranchIds($user);
        if ($requested !== null && ! in_array($requested, $allowed, true)) {
            abort(403, 'You cannot access this branch.', ['X-Error-Code' => 'BRANCH_ACCESS_DENIED']);
        }

        if (count($allowed) === 1) {
            return [
                'branch_id' => $allowed[0],
                'filters' => ['branch_id' => $allowed[0]],
            ];
        }

        if ($requested !== null) {
            return [
                'branch_id' => $requested,
                'filters' => ['branch_id' => $requested],
            ];
        }

        return [
            'branch_id' => null,
            'filters' => ['branch_ids' => $allowed],
        ];
    }
}
