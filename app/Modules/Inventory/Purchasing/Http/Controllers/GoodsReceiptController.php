<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Purchasing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Purchasing\DTOs\GoodsReceiptLineResponseData;
use App\Modules\Inventory\Purchasing\DTOs\GoodsReceiptResponseData;
use App\Modules\Inventory\Purchasing\Http\Requests\StoreGoodsReceiptRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\SyncGoodsReceiptLinesRequest;
use App\Modules\Inventory\Purchasing\Http\Requests\UpdateGoodsReceiptRequest;
use App\Modules\Inventory\Purchasing\Models\GoodsReceipt;
use App\Modules\Inventory\Purchasing\Services\GoodsReceiptService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $goodsReceiptService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->string('status')->toString() ?: null,
            'purchase_order_id' => $request->string('purchase_order_id')->toString() ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'search' => ListPagination::search($request),
            'from' => $request->string('from')->toString() ?: null,
            'to' => $request->string('to')->toString() ?: null,
        ];

        return ListPagination::jsonMapped(
            $this->goodsReceiptService->list($filters, ListPagination::perPage($request, 50)),
            fn ($receipts) => GoodsReceiptResponseData::collectionToArray($receipts, false),
            'Goods receipts fetched successfully.'
        );
    }

    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $userId = $request->user()?->id;
        $receipt = $this->goodsReceiptService->create(
            $request->validated(),
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::created(
            GoodsReceiptResponseData::fromModel($receipt),
            'Goods receipt created successfully.'
        );
    }

    public function show(GoodsReceipt $goods_receipt): JsonResponse
    {
        return ApiResponse::success(
            GoodsReceiptResponseData::fromModel($this->goodsReceiptService->find($goods_receipt->id)),
            'Goods receipt fetched successfully.'
        );
    }

    public function update(UpdateGoodsReceiptRequest $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        $receipt = $this->goodsReceiptService->updateHeader(
            $goods_receipt,
            $request->validated()
        );

        return ApiResponse::success(
            GoodsReceiptResponseData::fromModel($receipt),
            'Goods receipt updated successfully.'
        );
    }

    public function destroy(GoodsReceipt $goods_receipt): JsonResponse
    {
        $this->goodsReceiptService->delete($goods_receipt);

        return ApiResponse::success(null, 'Goods receipt deleted successfully.');
    }

    public function syncLines(SyncGoodsReceiptLinesRequest $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        $lines = $this->goodsReceiptService->syncLines(
            $goods_receipt,
            $request->validated('lines')
        );

        return ApiResponse::success(
            GoodsReceiptLineResponseData::collectionToArray($lines),
            'Goods receipt lines synced successfully.'
        );
    }

    public function post(Request $request, GoodsReceipt $goods_receipt): JsonResponse
    {
        $userId = $request->user()?->id;
        $receipt = $this->goodsReceiptService->post(
            $goods_receipt,
            $userId !== null ? (string) $userId : null
        );

        return ApiResponse::success(
            GoodsReceiptResponseData::fromModel($receipt),
            'Goods receipt posted successfully.'
        );
    }
}
