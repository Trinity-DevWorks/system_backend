<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Budget\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\Budget\Http\Requests\CopyBudgetActualsRequest;
use App\Modules\Accounting\Budget\Http\Requests\CopyBudgetFromRequest;
use App\Modules\Accounting\Budget\Http\Requests\ImportBudgetRequest;
use App\Modules\Accounting\Budget\Http\Requests\SaveBudgetLinesRequest;
use App\Modules\Accounting\Budget\Http\Requests\SpreadBudgetRequest;
use App\Modules\Accounting\Budget\Http\Requests\StoreBudgetRequest;
use App\Modules\Accounting\Budget\Http\Requests\UpdateBudgetRequest;
use App\Modules\Accounting\Budget\Models\Budget;
use App\Modules\Accounting\Budget\Services\BudgetService;
use App\Support\Export\TabularExportService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BudgetController extends Controller
{
    public function __construct(
        private readonly BudgetService $service,
        private readonly TabularExportService $exports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->service->paginate([
            'search' => ListPagination::search($request),
            'status' => $this->text($request, 'status'),
            'year' => $request->filled('year') ? $request->integer('year') : null,
            'branch_id' => $request->filled('branch_id') ? $request->integer('branch_id') : null,
        ], ListPagination::perPage($request));

        $totals = $this->service->statementTotals($page->getCollection());

        return ListPagination::json(
            $page,
            fn (Budget $budget): array => $this->service->present($budget, $totals[(string) $budget->id] ?? null),
            'Budgets fetched successfully.'
        );
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $budget = $this->service->create($request->validated(), $this->userId($request));

        return ApiResponse::created($this->service->present($budget), 'Budget created successfully.');
    }

    public function show(Budget $budget): JsonResponse
    {
        return ApiResponse::success(
            $this->service->present($this->service->find($budget)),
            'Budget fetched successfully.'
        );
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $budget = $this->service->update($budget, $request->validated());

        return ApiResponse::success($this->service->present($budget), 'Budget updated successfully.');
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $this->service->delete($budget);

        return ApiResponse::success(null, 'Budget deleted successfully.');
    }

    public function grid(Request $request, Budget $budget): JsonResponse
    {
        return ApiResponse::success(
            $this->service->grid($budget, $request->boolean('include_balance_sheet')),
            'Budget grid fetched successfully.'
        );
    }

    public function saveLines(SaveBudgetLinesRequest $request, Budget $budget): JsonResponse
    {
        /** @var list<array{account_id: int|string, period_month: string, amount: int|string|float}> $lines */
        $lines = $request->validated('lines');
        $this->service->saveLines($budget, $lines);

        return ApiResponse::success($this->service->grid($budget), 'Budget lines saved successfully.');
    }

    public function spread(SpreadBudgetRequest $request, Budget $budget): JsonResponse
    {
        $this->service->spreadEvenly($budget, $request->integer('account_id'), $request->input('amount'));

        return ApiResponse::success($this->service->grid($budget), 'Budget spread successfully.');
    }

    public function copyActuals(CopyBudgetActualsRequest $request, Budget $budget): JsonResponse
    {
        $this->service->copyActuals($budget, $request->integer('year'), $request->input('percent'));

        return ApiResponse::success($this->service->grid($budget), 'Actuals copied successfully.');
    }

    public function copyFrom(CopyBudgetFromRequest $request, Budget $budget): JsonResponse
    {
        $this->service->copyFrom($budget, (string) $request->validated('source_budget_id'), $request->input('percent'));

        return ApiResponse::success($this->service->grid($budget), 'Budget copied successfully.');
    }

    public function approve(Request $request, Budget $budget): JsonResponse
    {
        $budget = $this->service->approve($budget, $this->userId($request));

        return ApiResponse::success($this->service->present($budget), 'Budget approved successfully.');
    }

    public function revise(Request $request, Budget $budget): JsonResponse
    {
        $budget = $this->service->revise($budget, $this->userId($request));

        return ApiResponse::success($this->service->present($budget), 'Budget revised successfully.');
    }

    public function archive(Budget $budget): JsonResponse
    {
        return ApiResponse::success($this->service->present($this->service->archive($budget)), 'Budget archived successfully.');
    }

    public function export(Request $request, Budget $budget): Response
    {
        $format = (string) $request->query('format', 'xlsx');

        // ?template=1: every account (to fill in Excel and import); otherwise only budgeted accounts.
        return $this->exports->download(
            $this->service->document($budget, $request->boolean('template')),
            $format === 'pdf' ? 'pdf' : 'xlsx'
        );
    }

    public function import(ImportBudgetRequest $request, Budget $budget): JsonResponse
    {
        $file = $request->file('file');
        if ($file === null) {
            abort(422, 'Choose a file.', ['X-Error-Code' => 'BUDGET_IMPORT_INVALID']);
        }

        $errors = $this->service->import($budget, $file);
        if ($errors !== []) {
            return ApiResponse::error(
                'The budget import has invalid rows.',
                422,
                null,
                [],
                null,
                ['rows' => $errors],
                'BUDGET_IMPORT_INVALID',
            );
        }

        return ApiResponse::success($this->service->grid($budget), 'Budget imported successfully.');
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }

    private function text(Request $request, string $key): ?string
    {
        $value = trim((string) $request->query($key, ''));

        return $value === '' ? null : $value;
    }
}
