<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Modules\Accounting\Budget\Services\BudgetCheckService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Before posting, a drawer asks whether its saved document would go over budget.
 * Allowed for anyone who may add or edit that kind of document.
 */
class BudgetCheckController extends Controller
{
    /** source => RBAC resource */
    private const RESOURCES = [
        BudgetCheckService::SOURCE_EXPENSE_VOUCHER => 'expense_vouchers',
        BudgetCheckService::SOURCE_PURCHASE_INVOICE => 'purchase_invoices',
        BudgetCheckService::SOURCE_JOURNAL_ENTRY => 'journal_entries',
    ];

    public function __construct(
        private readonly BudgetCheckService $checks,
        private readonly PermissionService $permissions,
    ) {}

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => ['required', Rule::in(array_keys(self::RESOURCES))],
            'id' => ['required', 'uuid'],
            // true: the document is already posted (its amounts are already in the actuals).
            'posted' => ['sometimes', 'boolean'],
        ]);
        $user = $request->user();
        $resource = self::RESOURCES[$data['source']];
        if (! $user instanceof User
            || (! $this->permissions->userHas($resource, 'add', $user) && ! $this->permissions->userHas($resource, 'edit', $user))) {
            abort(403);
        }

        return ApiResponse::success(
            ['warnings' => $this->checks->checkDocument((string) $data['source'], (string) $data['id'], (bool) ($data['posted'] ?? false))],
            'Budget check completed.'
        );
    }
}
