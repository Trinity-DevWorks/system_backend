<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\LandedCost\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\LandedCost\Http\Requests\SaveLandedCostRequest;
use App\Modules\Inventory\Purchasing\LandedCost\Models\LandedCost;
use App\Modules\Inventory\Purchasing\LandedCost\Services\LandedCostPdfService;
use App\Modules\Inventory\Purchasing\LandedCost\Services\LandedCostService;
use App\Modules\Inventory\Purchasing\LandedCost\Services\LandedCostSources;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LandedCostController extends Controller
{
    public function __construct(
        private readonly LandedCostService $service,
        private readonly LandedCostSources $sources,
        private readonly LandedCostPdfService $pdf,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->service->paginate([
                'status' => $request->query('status') !== null ? (string) $request->query('status') : null,
                'search' => ListPagination::search($request),
                'from' => $request->query('from') !== null ? (string) $request->query('from') : null,
                'to' => $request->query('to') !== null ? (string) $request->query('to') : null,
            ], ListPagination::perPage($request)),
            fn (LandedCost $document): array => $this->service->toListRow($document),
            'Landed costs fetched successfully.'
        );
    }

    public function store(SaveLandedCostRequest $request): JsonResponse
    {
        $document = $this->service->create($request->validated(), $this->userId($request));

        return ApiResponse::created($this->service->toArray($document), 'Landed cost created successfully.');
    }

    public function show(LandedCost $landed_cost): JsonResponse
    {
        return ApiResponse::success($this->service->toArray($this->service->find((string) $landed_cost->id)), 'Landed cost fetched successfully.');
    }

    public function update(SaveLandedCostRequest $request, LandedCost $landed_cost): JsonResponse
    {
        return ApiResponse::success($this->service->toArray($this->service->update($landed_cost, $request->validated())), 'Landed cost updated successfully.');
    }

    public function destroy(LandedCost $landed_cost): JsonResponse
    {
        $this->service->delete($landed_cost);

        return ApiResponse::success(null, 'Landed cost deleted successfully.');
    }

    public function preview(SaveLandedCostRequest $request): JsonResponse
    {
        $id = $request->query('landed_cost_id');

        return ApiResponse::success($this->service->preview($request->validated(), is_string($id) && $id !== '' ? $id : null), 'Landed cost preview.');
    }

    public function sources(Request $request): JsonResponse
    {
        $filters = [
            'supplier_id' => $request->query('supplier_id') !== null ? (string) $request->query('supplier_id') : null,
            'search' => $request->query('search') !== null ? trim((string) $request->query('search')) : null,
            'from' => $request->query('from') !== null ? (string) $request->query('from') : null,
            'to' => $request->query('to') !== null ? (string) $request->query('to') : null,
        ];
        $exclude = $request->query('landed_cost_id');
        $exclude = is_string($exclude) && $exclude !== '' ? $exclude : null;

        $data = $request->query('type') === 'charges'
            ? $this->sources->openCharges($filters, $exclude)
            : $this->sources->receipts($filters);

        return ApiResponse::success($data, 'Landed cost sources fetched successfully.');
    }

    public function post(Request $request, LandedCost $landed_cost): JsonResponse
    {
        return ApiResponse::success($this->service->toArray($this->service->post($landed_cost, $this->userId($request))), 'Landed cost posted successfully.');
    }

    public function reverse(Request $request, LandedCost $landed_cost): JsonResponse
    {
        return ApiResponse::success($this->service->toArray($this->service->reverse($landed_cost, $this->userId($request))), 'Landed cost reversed successfully.');
    }

    public function pdf(Request $request, LandedCost $landed_cost): Response
    {
        $locale = $request->query('locale');

        return $this->pdf->download($landed_cost, is_string($locale) ? $locale : null);
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
