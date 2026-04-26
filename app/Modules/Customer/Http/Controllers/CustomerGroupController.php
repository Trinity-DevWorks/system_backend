<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Customer\DTOs\CustomerGroupData;
use App\Modules\Customer\DTOs\CustomerGroupResponseData;
use App\Modules\Customer\Http\Requests\StoreCustomerGroupRequest;
use App\Modules\Customer\Http\Requests\UpdateCustomerGroupRequest;
use App\Modules\Customer\Models\CustomerGroup;
use App\Modules\Customer\Services\CustomerGroupService;
use Illuminate\Http\JsonResponse;

class CustomerGroupController extends Controller
{
    public function __construct(
        private readonly CustomerGroupService $customerGroupService
    ) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success(
            CustomerGroupResponseData::collectionToArray($this->customerGroupService->list()),
            'Customer groups fetched successfully.'
        );
    }

    public function store(StoreCustomerGroupRequest $request): JsonResponse
    {
        $group = $this->customerGroupService->create(CustomerGroupData::fromStoreRequest($request));

        return ApiResponse::created(
            CustomerGroupResponseData::fromModel($group)->toArray(),
            'Customer group created successfully.'
        );
    }

    public function show(CustomerGroup $customer_group): JsonResponse
    {
        return ApiResponse::success(
            CustomerGroupResponseData::fromModel($customer_group)->toArray(),
            'Customer group fetched successfully.'
        );
    }

    public function update(UpdateCustomerGroupRequest $request, CustomerGroup $customer_group): JsonResponse
    {
        $updated = $this->customerGroupService->update($customer_group, CustomerGroupData::fromUpdateRequest($request));

        return ApiResponse::success(
            CustomerGroupResponseData::fromModel($updated)->toArray(),
            'Customer group updated successfully.'
        );
    }

    public function destroy(CustomerGroup $customer_group): JsonResponse
    {
        $this->customerGroupService->delete($customer_group);

        return ApiResponse::success(null, 'Customer group deleted successfully.');
    }
}
