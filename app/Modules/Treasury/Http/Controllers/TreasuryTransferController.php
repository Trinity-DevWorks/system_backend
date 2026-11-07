<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Treasury\DTOs\TreasuryResponseData;
use App\Modules\Treasury\Http\Requests\SaveTreasuryTransferRequest;
use App\Modules\Treasury\Models\TreasuryTransfer;
use App\Modules\Treasury\Services\TreasuryTransferService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TreasuryTransferController extends Controller
{
    public function __construct(private readonly TreasuryTransferService $service) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->service->paginate([
                'status' => (string) $request->query('status', ''),
                'search' => ListPagination::search($request),
            ], ListPagination::perPage($request)),
            fn (TreasuryTransfer $t): array => TreasuryResponseData::transfer($t),
            'Transfers fetched successfully.'
        );
    }

    public function store(SaveTreasuryTransferRequest $request): JsonResponse
    {
        return ApiResponse::created(
            TreasuryResponseData::transfer($this->service->create($request->validated(), $this->userId($request))),
            'Transfer created successfully.'
        );
    }

    public function show(TreasuryTransfer $treasury_transfer): JsonResponse
    {
        return ApiResponse::success(TreasuryResponseData::transfer($this->service->find((string) $treasury_transfer->id)), 'Transfer fetched successfully.');
    }

    public function update(SaveTreasuryTransferRequest $request, TreasuryTransfer $treasury_transfer): JsonResponse
    {
        return ApiResponse::success(
            TreasuryResponseData::transfer($this->service->update($treasury_transfer, $request->validated())),
            'Transfer updated successfully.'
        );
    }

    public function destroy(TreasuryTransfer $treasury_transfer): JsonResponse
    {
        $this->service->delete($treasury_transfer);

        return ApiResponse::success(null, 'Transfer deleted successfully.');
    }

    public function post(Request $request, TreasuryTransfer $treasury_transfer): JsonResponse
    {
        return ApiResponse::success(
            TreasuryResponseData::transfer($this->service->post($treasury_transfer, $this->userId($request))),
            'Transfer posted successfully.'
        );
    }

    public function reverse(Request $request, TreasuryTransfer $treasury_transfer): JsonResponse
    {
        return ApiResponse::success(
            TreasuryResponseData::transfer($this->service->reverse($treasury_transfer, $this->userId($request))),
            'Transfer reversed successfully.'
        );
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->id;

        return $id !== null ? (string) $id : null;
    }
}
