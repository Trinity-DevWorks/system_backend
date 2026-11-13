<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Accounting\FixedAsset\Http\Requests\ChangeFixedAssetEstimateRequest;
use App\Modules\Accounting\FixedAsset\Http\Requests\DisposeFixedAssetRequest;
use App\Modules\Accounting\FixedAsset\Http\Requests\StoreFixedAssetRequest;
use App\Modules\Accounting\FixedAsset\Http\Requests\UpdateFixedAssetRequest;
use App\Modules\Accounting\FixedAsset\Models\FixedAsset;
use App\Modules\Accounting\FixedAsset\Services\FixedAssetPdfService;
use App\Modules\Accounting\FixedAsset\Services\FixedAssetService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class FixedAssetController extends Controller
{
    public function __construct(
        private readonly FixedAssetService $assets,
        private readonly FixedAssetPdfService $pdfs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->assets->paginate([
                'search' => ListPagination::search($request),
                'status' => $request->query('status'),
                'category_id' => $request->query('category_id'),
                'branch_id' => $request->query('branch_id'),
                'purchase_invoice_id' => $request->query('purchase_invoice_id'),
            ], ListPagination::perPage($request)),
            fn (FixedAsset $asset): array => $this->assets->listRow($asset),
            'Fixed assets fetched successfully.',
        );
    }

    public function show(FixedAsset $fixedAsset): JsonResponse
    {
        return ApiResponse::success($this->assets->detail($this->assets->find((string) $fixedAsset->id)), 'Fixed asset fetched successfully.');
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        $asset = $this->assets->create($request->validated(), $this->userId($request));

        return ApiResponse::success($this->assets->detail($asset), 'Fixed asset created successfully.', 201);
    }

    public function update(UpdateFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assets->update($fixedAsset, $request->validated());

        return ApiResponse::success($this->assets->detail($asset), 'Fixed asset updated successfully.');
    }

    public function destroy(FixedAsset $fixedAsset): JsonResponse
    {
        $this->assets->delete($fixedAsset);

        return ApiResponse::success(null, 'Fixed asset deleted successfully.');
    }

    public function activate(Request $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assets->activate($fixedAsset, $this->userId($request));

        return ApiResponse::success($this->assets->detail($asset), 'Fixed asset activated successfully.');
    }

    public function estimate(ChangeFixedAssetEstimateRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assets->changeEstimate($fixedAsset, $request->validated());

        return ApiResponse::success($this->assets->detail($asset), 'Fixed asset estimate updated successfully.');
    }

    public function dispose(DisposeFixedAssetRequest $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assets->dispose($fixedAsset, $request->validated(), $this->userId($request));

        return ApiResponse::success($this->assets->detail($asset), 'Fixed asset disposed successfully.');
    }

    public function reverseDisposal(Request $request, FixedAsset $fixedAsset): JsonResponse
    {
        $asset = $this->assets->reverseDisposal($fixedAsset, $this->userId($request));

        return ApiResponse::success($this->assets->detail($asset), 'Fixed asset disposal reversed successfully.');
    }

    public function pdf(Request $request, FixedAsset $fixedAsset): Response
    {
        $pdf = $this->pdfs->render($fixedAsset, $request->query('locale'));

        return $pdf->download($this->pdfs->downloadFilename($fixedAsset));
    }

    private function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : null;
    }
}
