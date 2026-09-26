<?php

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\DTOs\InvoiceItemResponseData;
use App\Modules\Inventory\Item\DTOs\ItemData;
use App\Modules\Inventory\Item\DTOs\ItemResponseData;
use App\Modules\Inventory\Item\Http\Requests\StoreItemRequest;
use App\Modules\Inventory\Item\Http\Requests\UpdateItemRequest;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\Item\Services\ItemService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly ItemService $itemService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $section = $this->resolveListSection($request, ['names', 'for-invoice']);

        if ($section === 'names') {
            return ApiResponse::success(
                $this->itemService->names()->map(fn (Item $item): array => [
                    'id' => $item->id,
                    'item_code' => $item->item_code,
                    'name' => $item->name,
                    'track_inventory' => (bool) $item->track_inventory,
                    'track_lots' => (bool) $item->track_lots,
                    'allow_sale' => (bool) $item->allow_sale,
                    'allow_purchase' => (bool) $item->allow_purchase,
                    'is_active' => (bool) $item->is_active,
                    'vat_group_id' => $item->vat_group_id !== null ? (int) $item->vat_group_id : null,
                    'vat_group' => $item->vatGroup
                        ? [
                            'id' => $item->vatGroup->id,
                            'percentage' => (string) $item->vatGroup->percentage,
                        ]
                        : null,
                    'base_uom' => $item->baseUom
                        ? [
                            'id' => $item->baseUom->id,
                            'code' => $item->baseUom->code,
                            'name' => $item->baseUom->name,
                        ]
                        : null,
                    'item_type' => $item->itemType
                        ? [
                            'id' => $item->itemType->id,
                            'code' => $item->itemType->code,
                            'name' => $item->itemType->name,
                        ]
                        : null,
                ])->values()->all(),
                'Item names fetched successfully.'
            );
        }

        if ($section === 'for-invoice') {
            $context = strtolower(trim((string) $request->query('context', 'sale')));
            if (! in_array($context, ['sale', 'purchase'], true)) {
                abort(422, 'Invalid invoice item context.', ['X-Error-Code' => 'ITEM_INVOICE_CONTEXT_INVALID']);
            }

            return ListPagination::jsonMapped(
                $this->itemService->paginateForInvoice(
                    $context,
                    ListPagination::search($request),
                    ListPagination::perPage($request),
                ),
                fn ($items) => InvoiceItemResponseData::searchCollection($items),
                'Invoice items fetched successfully.'
            );
        }

        return ListPagination::jsonMapped(
            $this->itemService->paginateForTable(
                ListPagination::search($request),
                ListPagination::perPage($request),
                [
                    'allow_sale' => $request->exists('allow_sale') ? $request->boolean('allow_sale') : null,
                    'allow_purchase' => $request->exists('allow_purchase') ? $request->boolean('allow_purchase') : null,
                    'is_active' => $request->exists('is_active') ? $request->boolean('is_active') : null,
                ],
            ),
            fn ($items) => ItemResponseData::collectionToArray($items),
            'Items fetched successfully.'
        );
    }

    /**
     * UOMs + barcodes + prices for one selected invoice line item.
     */
    public function invoiceLineSetup(Item $item): JsonResponse
    {
        $loaded = $this->itemService->findForInvoiceLineSetup($item);

        return ApiResponse::success(
            InvoiceItemResponseData::lineSetup($loaded),
            'Invoice item line setup fetched successfully.'
        );
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->itemService->create(ItemData::fromStoreRequest($request));

        return ApiResponse::created(
            ItemResponseData::fromModel($item)->toArray(),
            'Item created successfully.'
        );
    }

    public function show(Item $item): JsonResponse
    {
        $item->load(['itemType', 'category', 'brand', 'unitGroup', 'baseUom']);

        return ApiResponse::success(
            ItemResponseData::fromModel($item)->toArray(),
            'Item fetched successfully.'
        );
    }

    public function update(UpdateItemRequest $request, Item $item): JsonResponse
    {
        $updated = $this->itemService->update($item, ItemData::fromUpdateRequest($request, $item));

        return ApiResponse::success(
            ItemResponseData::fromModel($updated)->toArray(),
            'Item updated successfully.'
        );
    }

    public function destroy(Item $item): JsonResponse
    {
        $this->itemService->delete($item);

        return ApiResponse::success(null, 'Item deleted successfully.');
    }
}
