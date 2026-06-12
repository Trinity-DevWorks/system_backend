<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Supplier\DTOs\SupplierContactResponseData;
use App\Modules\Supplier\Http\Requests\StoreSupplierContactRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierContactRequest;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Models\SupplierContact;
use App\Modules\Supplier\Services\SupplierContactService;
use Illuminate\Http\JsonResponse;

class SupplierContactController extends Controller
{
    public function __construct(
        private readonly SupplierContactService $contactService
    ) {}

    public function index(Supplier $supplier): JsonResponse
    {
        return ApiResponse::success(
            SupplierContactResponseData::collectionToArray(
                $supplier->contacts()->orderBy('name')->get()
            ),
            'Supplier contacts fetched successfully.'
        );
    }

    public function store(StoreSupplierContactRequest $request, Supplier $supplier): JsonResponse
    {
        $contact = $this->contactService->create($supplier, $request->validated());

        return ApiResponse::created(
            SupplierContactResponseData::fromModel($contact)->toArray(),
            'Contact created successfully.'
        );
    }

    public function show(Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        $this->ensureScoped($supplier, $contact);

        return ApiResponse::success(
            SupplierContactResponseData::fromModel($contact)->toArray(),
            'Contact fetched successfully.'
        );
    }

    public function update(UpdateSupplierContactRequest $request, Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        $this->ensureScoped($supplier, $contact);
        $contact = $this->contactService->update($contact, $request->validated());

        return ApiResponse::success(
            SupplierContactResponseData::fromModel($contact)->toArray(),
            'Contact updated successfully.'
        );
    }

    public function destroy(Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        $this->ensureScoped($supplier, $contact);
        $this->contactService->delete($contact);

        return ApiResponse::success(null, 'Contact deleted successfully.');
    }

    private function ensureScoped(Supplier $supplier, SupplierContact $contact): void
    {
        if ((string) $contact->supplier_id !== (string) $supplier->id) {
            abort(404, 'Contact not found for this supplier.', ['X-Error-Code' => 'SUPPLIER_CONTACT_SCOPE_MISMATCH']);
        }
    }
}
