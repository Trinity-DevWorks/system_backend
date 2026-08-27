<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\BundleExplosionLineResponseData;
use App\Modules\Inventory\Stock\DTOs\BundleExplosionResponseData;
use App\Modules\Inventory\Stock\Http\Requests\StoreBundleExplosionRequest;
use App\Modules\Inventory\Stock\Http\Requests\SyncBundleExplosionLinesRequest;
use App\Modules\Inventory\Stock\Http\Requests\UpdateBundleExplosionRequest;
use App\Modules\Inventory\Stock\Models\BundleExplosion;
use App\Modules\Inventory\Stock\Services\BundleExplosionService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BundleExplosionController extends Controller
{
    public function __construct(
        private readonly BundleExplosionService $bundleExplosionService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'item_id' => $request->string('item_id')->toString() ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::json(
            $this->bundleExplosionService->list($filters, ListPagination::perPage($request, 50)),
            fn (BundleExplosion $document): array => BundleExplosionResponseData::fromModel($document, false),
            'Bundle explosions fetched successfully.'
        );
    }

    public function store(StoreBundleExplosionRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->bundleExplosionService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            BundleExplosionResponseData::fromModel($document),
            'Bundle explosion created successfully.'
        );
    }

    public function show(BundleExplosion $bundle_explosion): JsonResponse
    {
        return ApiResponse::success(
            BundleExplosionResponseData::fromModel($this->bundleExplosionService->find($bundle_explosion->id)),
            'Bundle explosion fetched successfully.'
        );
    }

    public function update(UpdateBundleExplosionRequest $request, BundleExplosion $bundle_explosion): JsonResponse
    {
        $document = $this->bundleExplosionService->updateHeader(
            $bundle_explosion,
            $request->validated()
        );

        return ApiResponse::success(
            BundleExplosionResponseData::fromModel($document),
            'Bundle explosion updated successfully.'
        );
    }

    public function destroy(BundleExplosion $bundle_explosion): JsonResponse
    {
        $this->bundleExplosionService->delete($bundle_explosion);

        return ApiResponse::success(null, 'Bundle explosion deleted successfully.');
    }

    public function syncLines(SyncBundleExplosionLinesRequest $request, BundleExplosion $bundle_explosion): JsonResponse
    {
        $lines = $this->bundleExplosionService->syncLines(
            $bundle_explosion,
            $request->validated('lines')
        );

        return ApiResponse::success(
            BundleExplosionLineResponseData::collectionToArray($lines),
            'Bundle explosion lines synced successfully.'
        );
    }

    public function post(Request $request, BundleExplosion $bundle_explosion): JsonResponse
    {
        $userId = $request->user()?->id;
        $document = $this->bundleExplosionService->post(
            $bundle_explosion,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            BundleExplosionResponseData::fromModel($document),
            'Bundle explosion posted successfully.'
        );
    }
}
