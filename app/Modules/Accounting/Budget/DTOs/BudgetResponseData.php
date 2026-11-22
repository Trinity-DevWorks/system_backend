<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\DTOs;

use App\Modules\Accounting\Budget\Models\Budget;

final class BudgetResponseData
{
    /**
     * @param  array{revenue: string, expense: string}  $totals
     * @return array<string, mixed>
     */
    public static function budget(Budget $budget, array $totals): array
    {
        return [
            'id' => (string) $budget->id,
            'name' => $budget->name,
            'year' => (int) $budget->fiscal_year_start->year,
            'fiscal_year_start' => $budget->fiscal_year_start->toDateString(),
            'fiscal_year_end' => $budget->fiscal_year_end->toDateString(),
            'branch_id' => $budget->branch_id !== null ? (int) $budget->branch_id : null,
            'branch_name' => $budget->relationLoaded('branch') ? $budget->branch?->name : null,
            'version' => (int) $budget->version,
            'parent_budget_id' => $budget->parent_budget_id,
            'status' => $budget->status->value,
            'notes' => $budget->notes,
            'revenue_total' => $totals['revenue'],
            'expense_total' => $totals['expense'],
            'approved_by' => $budget->approved_by,
            'approved_by_name' => $budget->relationLoaded('approvedByUser') ? $budget->approvedByUser?->name : null,
            'approved_at' => $budget->approved_at?->toISOString(),
            'created_by' => $budget->created_by,
            'created_by_name' => $budget->relationLoaded('createdByUser') ? $budget->createdByUser?->name : null,
            'created_at' => (string) $budget->created_at,
            'updated_at' => (string) $budget->updated_at,
        ];
    }
}
