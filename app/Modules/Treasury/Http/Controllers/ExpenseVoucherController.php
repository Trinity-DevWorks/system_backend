<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Treasury\DTOs\TreasuryResponseData;
use App\Modules\Treasury\Http\Requests\SaveExpenseVoucherRequest;
use App\Modules\Treasury\Models\ExpenseVoucher;
use App\Modules\Treasury\Services\ExpenseVoucherService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseVoucherController extends Controller
{
    public function __construct(private readonly ExpenseVoucherService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->service->paginate([
                'status' => (string) $request->query('status', ''),
                'search' => ListPagination::search($request),
            ], ListPagination::perPage($request)),
            fn (ExpenseVoucher $v): array => TreasuryResponseData::voucher($v),
            'Expense vouchers fetched successfully.'
        );
    }

    public function store(SaveExpenseVoucherRequest $request): JsonResponse
    {
        return ApiResponse::created(
            TreasuryResponseData::voucher($this->service->create($request->validated(), $this->userId($request))),
            'Expense voucher created successfully.'
        );
    }

    public function show(ExpenseVoucher $expense_voucher): JsonResponse
    {
        return ApiResponse::success(TreasuryResponseData::voucher($this->service->find((string) $expense_voucher->id)), 'Expense voucher fetched successfully.');
    }

    public function update(SaveExpenseVoucherRequest $request, ExpenseVoucher $expense_voucher): JsonResponse
    {
        return ApiResponse::success(
            TreasuryResponseData::voucher($this->service->update($expense_voucher, $request->validated())),
            'Expense voucher updated successfully.'
        );
    }

    public function destroy(ExpenseVoucher $expense_voucher): JsonResponse
    {
        $this->service->delete($expense_voucher);

        return ApiResponse::success(null, 'Expense voucher deleted successfully.');
    }

    public function post(Request $request, ExpenseVoucher $expense_voucher): JsonResponse
    {
        return ApiResponse::success(
            TreasuryResponseData::voucher($this->service->post($expense_voucher, $this->userId($request))),
            'Expense voucher posted successfully.'
        );
    }

    public function reverse(Request $request, ExpenseVoucher $expense_voucher): JsonResponse
    {
        return ApiResponse::success(
            TreasuryResponseData::voucher($this->service->reverse($expense_voucher, $this->userId($request))),
            'Expense voucher reversed successfully.'
        );
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
