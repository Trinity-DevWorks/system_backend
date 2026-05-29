<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Supplier\DTOs\SupplierItemData;
use App\Modules\Supplier\DTOs\SupplierItemResponseData;
use App\Modules\Supplier\Http\Requests\StoreSupplierItemRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierItemRequest;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierItem;
use App\Modules\Supplier\Services\SupplierItemService;
use Illuminate\Http\JsonResponse;

class SupplierItemController extends Controller
{
    public function __construct(
        private readonly SupplierItemService $supplierItemService
    ) {}

    public function index(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success(
            SupplierItemResponseData::collectionToArray(
                $this->supplierItemService->listForSupplier($supplier)
            ),
            'Supplier items fetched successfully.'
        );
    }

    public function indexForItem(Item $item): JsonResponse
    {
        return ApiResponse::success(
            SupplierItemResponseData::collectionToArray(
                $this->supplierItemService->listForItem($item)
            ),
            'Item suppliers fetched successfully.'
        );
    }

    public function store(StoreSupplierItemRequest $request, Supplier $supplier): JsonResponse
    {
        $row = $this->supplierItemService->create(
            $supplier,
            SupplierItemData::fromStoreRequest($request)
        );

        return ApiResponse::created(
            SupplierItemResponseData::fromModel($row),
            'Supplier item created successfully.'
        );
    }

    public function show(Supplier $supplier, SupplierItem $supplierItem): JsonResponse
    {
        $this->ensureScoped($supplier, $supplierItem);

        return ApiResponse::success(
            SupplierItemResponseData::fromModel($supplierItem),
            'Supplier item fetched successfully.'
        );
    }

    public function update(UpdateSupplierItemRequest $request, Supplier $supplier, SupplierItem $supplierItem): JsonResponse
    {
        $this->ensureScoped($supplier, $supplierItem);

        $row = $this->supplierItemService->update(
            $supplierItem,
            SupplierItemData::fromUpdateRequest($request, $supplierItem)
        );

        return ApiResponse::success(
            SupplierItemResponseData::fromModel($row),
            'Supplier item updated successfully.'
        );
    }

    public function destroy(Supplier $supplier, SupplierItem $supplierItem): JsonResponse
    {
        $this->ensureScoped($supplier, $supplierItem);
        $this->supplierItemService->delete($supplierItem);

        return ApiResponse::success(null, 'Supplier item deleted successfully.');
    }

    private function ensureScoped(Supplier $supplier, SupplierItem $supplierItem): void
    {
        if ((int) $supplierItem->supplier_id !== (int) $supplier->id) {
            abort(404, 'Supplier item not found for this supplier.', ['X-Error-Code' => 'SUPPLIER_ITEM_SCOPE_MISMATCH']);
        }
    }
}
