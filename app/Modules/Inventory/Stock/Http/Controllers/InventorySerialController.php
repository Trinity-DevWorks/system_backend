<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Stock\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Stock\DTOs\InventorySerialResponseData;
use App\Modules\Inventory\Stock\Services\InventorySerialQueryService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventorySerialController extends Controller
{
    public function __construct(
        private readonly InventorySerialQueryService $queries,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $filters = [
            'item_id' => $request->query('item_id') ?: null,
            'warehouse_id' => $request->integer('warehouse_id') ?: null,
            'status' => is_string($status) ? array_filter(explode(',', $status)) : (is_array($status) ? $status : null),
            'customer_id' => $request->query('customer_id') ?: null,
            'supplier_id' => $request->query('supplier_id') ?: null,
            'warranty' => $request->query('warranty') ?: null,
            'search' => ListPagination::search($request),
            'sort' => $request->query('sort') ?: null,
        ];

        return ListPagination::jsonMapped(
            $this->queries->paginate($filters, ListPagination::perPage($request)),
            fn ($serials): array => InventorySerialResponseData::collection($serials),
            'Serial numbers fetched successfully.'
        );
    }

    public function available(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return ApiResponse::success(
            $this->queries->available(
                (string) $validated['item_id'],
                (int) $validated['warehouse_id'],
                isset($validated['search']) ? (string) $validated['search'] : null,
            ),
            'Available serial numbers fetched successfully.'
        );
    }

    public function show(int $inventory_serial): JsonResponse
    {
        $serial = $this->queries->find($inventory_serial);

        return ApiResponse::success(
            InventorySerialResponseData::detail($serial, $this->queries->history($serial)),
            'Serial number fetched successfully.'
        );
    }

    public function update(Request $request, int $inventory_serial): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'warranty_ends_on' => ['nullable', 'date'],
        ]);
        $serial = $this->queries->find($inventory_serial);
        $serial->update([
            'notes' => isset($validated['notes']) && trim((string) $validated['notes']) !== '' ? trim((string) $validated['notes']) : null,
            'warranty_ends_on' => $validated['warranty_ends_on'] ?? null,
        ]);
        $serial = $this->queries->find($inventory_serial);

        return ApiResponse::success(
            InventorySerialResponseData::detail($serial, $this->queries->history($serial)),
            'Serial number updated successfully.'
        );
    }
}
