<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Customer\DTOs\CustomerAddressResponseData;
use App\Modules\Customer\Http\Requests\StoreCustomerAddressRequest;
use App\Modules\Customer\Http\Requests\UpdateCustomerAddressRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerAddress;
use App\Modules\Customer\Services\CustomerAddressService;
use Illuminate\Http\JsonResponse;

class CustomerAddressController extends Controller
{
    public function __construct(
        private readonly CustomerAddressService $addressService
    ) {}

    public function index(Customer $customer): JsonResponse
    {
        return ApiResponse::success(
            CustomerAddressResponseData::collectionToArray(
                $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
            ),
            'Customer addresses fetched successfully.'
        );
    }

    public function store(StoreCustomerAddressRequest $request, Customer $customer): JsonResponse
    {
        $address = $this->addressService->create($customer, $request->validated());

        return ApiResponse::created(
            CustomerAddressResponseData::fromModel($address)->toArray(),
            'Address created successfully.'
        );
    }

    public function show(Customer $customer, CustomerAddress $address): JsonResponse
    {
        $this->ensureScoped($customer, $address);

        return ApiResponse::success(
            CustomerAddressResponseData::fromModel($address)->toArray(),
            'Address fetched successfully.'
        );
    }

    public function update(UpdateCustomerAddressRequest $request, Customer $customer, CustomerAddress $address): JsonResponse
    {
        $this->ensureScoped($customer, $address);
        $address = $this->addressService->update($address, $request->validated());

        return ApiResponse::success(
            CustomerAddressResponseData::fromModel($address)->toArray(),
            'Address updated successfully.'
        );
    }

    public function destroy(Customer $customer, CustomerAddress $address): JsonResponse
    {
        $this->ensureScoped($customer, $address);
        $this->addressService->delete($address);

        return ApiResponse::success(null, 'Address deleted successfully.');
    }

    private function ensureScoped(Customer $customer, CustomerAddress $address): void
    {
        if ((string) $address->customer_id !== (string) $customer->id) {
            abort(404, 'Address not found for this customer.', ['X-Error-Code' => 'CUSTOMER_ADDRESS_SCOPE_MISMATCH']);
        }
    }
}
