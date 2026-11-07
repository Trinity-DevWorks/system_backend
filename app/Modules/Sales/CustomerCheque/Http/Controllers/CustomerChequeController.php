<?php

declare(strict_types=1);

namespace App\Modules\Sales\CustomerCheque\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Sales\CustomerCheque\Models\CustomerCheque;
use App\Modules\Sales\CustomerCheque\Services\CustomerChequeService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerChequeController extends Controller
{
    public function __construct(
        private readonly CustomerChequeService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'customer_id' => $request->query('customer_id'),
            'search' => ListPagination::search($request),
            'due_from' => $request->query('due_from'),
            'due_to' => $request->query('due_to'),
        ];

        return ListPagination::json(
            $this->service->list($filters, ListPagination::perPage($request)),
            fn (CustomerCheque $cheque): array => CustomerChequeService::toArray($cheque),
            'Customer cheques fetched successfully.'
        );
    }

    public function show(CustomerCheque $customerCheque): JsonResponse
    {
        return ApiResponse::success(CustomerChequeService::toArray($this->service->find((string) $customerCheque->id)), 'Customer cheque fetched successfully.');
    }

    public function clear(Request $request, CustomerCheque $customerCheque): JsonResponse
    {
        $data = $request->validate([
            'cash_bank_account_id' => ['required', 'integer', 'exists:cash_bank_accounts,id'],
            'date' => ['nullable', 'date'],
        ]);
        $cheque = $this->service->clear($customerCheque, (int) $data['cash_bank_account_id'], $data['date'] ?? null, $this->userId($request));

        return ApiResponse::success(CustomerChequeService::toArray($cheque), 'Cheque cleared successfully.');
    }

    public function bounce(Request $request, CustomerCheque $customerCheque): JsonResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $cheque = $this->service->bounce($customerCheque, $data['date'] ?? null, $this->userId($request));

        return ApiResponse::success(CustomerChequeService::toArray($cheque), 'Cheque marked as bounced.');
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
