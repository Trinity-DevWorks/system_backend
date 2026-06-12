<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Customer\DTOs\CustomerContactResponseData;
use App\Modules\Customer\Http\Requests\StoreCustomerContactRequest;
use App\Modules\Customer\Http\Requests\UpdateCustomerContactRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Models\CustomerContact;
use App\Modules\Customer\Services\CustomerContactService;
use Illuminate\Http\JsonResponse;

class CustomerContactController extends Controller
{
    public function __construct(
        private readonly CustomerContactService $contactService
    ) {}

    public function index(Customer $customer): JsonResponse
    {
        return ApiResponse::success(
            CustomerContactResponseData::collectionToArray(
                $customer->contacts()->orderBy('name')->get()
            ),
            'Customer contacts fetched successfully.'
        );
    }

    public function store(StoreCustomerContactRequest $request, Customer $customer): JsonResponse
    {
        $contact = $this->contactService->create($customer, $request->validated());

        return ApiResponse::created(
            CustomerContactResponseData::fromModel($contact)->toArray(),
            'Contact created successfully.'
        );
    }

    public function show(Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->ensureScoped($customer, $contact);

        return ApiResponse::success(
            CustomerContactResponseData::fromModel($contact)->toArray(),
            'Contact fetched successfully.'
        );
    }

    public function update(UpdateCustomerContactRequest $request, Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->ensureScoped($customer, $contact);
        $contact = $this->contactService->update($contact, $request->validated());

        return ApiResponse::success(
            CustomerContactResponseData::fromModel($contact)->toArray(),
            'Contact updated successfully.'
        );
    }

    public function destroy(Customer $customer, CustomerContact $contact): JsonResponse
    {
        $this->ensureScoped($customer, $contact);
        $this->contactService->delete($contact);

        return ApiResponse::success(null, 'Contact deleted successfully.');
    }

    private function ensureScoped(Customer $customer, CustomerContact $contact): void
    {
        if ((string) $contact->customer_id !== (string) $customer->id) {
            abort(404, 'Contact not found for this customer.', ['X-Error-Code' => 'CUSTOMER_CONTACT_SCOPE_MISMATCH']);
        }
    }
}
