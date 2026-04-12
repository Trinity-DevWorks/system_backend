<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Supplier\DTOs\SupplierAddressResponseData;
use App\Modules\Supplier\Http\Requests\StoreSupplierAddressRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierAddressRequest;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierAddress;
use App\Modules\Supplier\Services\SupplierAddressService;
use Illuminate\Http\JsonResponse;

class SupplierAddressController extends Controller
{
    public function __construct(
        private readonly SupplierAddressService $addressService
    ) {}

    public function index(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success(
            SupplierAddressResponseData::collectionToArray(
                $supplier->addresses()->orderByDesc('is_default')->orderBy('id')->get()
            ),
            'Supplier addresses fetched successfully.'
        );
    }

    public function store(StoreSupplierAddressRequest $request, Supplier $supplier): JsonResponse
    {
        $address = $this->addressService->create($supplier, $request->validated());

        return ApiResponse::created(
            SupplierAddressResponseData::fromModel($address)->toArray(),
            'Address created successfully.'
        );
    }

    public function show(Supplier $supplier, SupplierAddress $address): JsonResponse
    {
        $this->ensureScoped($supplier, $address);

        return ApiResponse::success(
            SupplierAddressResponseData::fromModel($address)->toArray(),
            'Address fetched successfully.'
        );
    }

    public function update(UpdateSupplierAddressRequest $request, Supplier $supplier, SupplierAddress $address): JsonResponse
    {
        $this->ensureScoped($supplier, $address);
        $address = $this->addressService->update($address, $request->validated());

        return ApiResponse::success(
            SupplierAddressResponseData::fromModel($address)->toArray(),
            'Address updated successfully.'
        );
    }

    public function destroy(Supplier $supplier, SupplierAddress $address): JsonResponse
    {
        $this->ensureScoped($supplier, $address);
        $this->addressService->delete($address);

        return ApiResponse::success(null, 'Address deleted successfully.');
    }

    private function ensureScoped(Supplier $supplier, SupplierAddress $address): void
    {
        if ((int) $address->supplier_id !== (int) $supplier->id) {
            abort(404);
        }
    }
}
