<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Concerns\ResolvesShowSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Customer\DTOs\CustomerAddressResponseData;
use App\Modules\Customer\DTOs\CustomerContactResponseData;
use App\Modules\Customer\DTOs\CustomerLedgerEntryResponseData;
use App\Modules\Customer\DTOs\CustomerResponseData;
use App\Modules\Customer\Http\Requests\StoreCustomerRequest;
use App\Modules\Customer\Http\Requests\UpdateCustomerRequest;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Customer\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    use ResolvesListSection;
    use ResolvesShowSection;

    private const INDEX_SECTIONS = ['names'];

    private const SHOW_SECTIONS = ['summary', 'full'];

    public function __construct(
        private readonly CustomerService $customerService,
        private readonly CustomerLedgerService $ledgerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->resolveListSection($request, self::INDEX_SECTIONS) === 'names') {
            $rows = $this->customerService->names()->map(fn (Customer $c): array => [
                'id' => $c->id,
                'customer_code' => (string) ($c->customer_code ?? ''),
                'name' => $c->name,
                'is_active' => (bool) $c->is_active,
                'created_at' => (string) $c->created_at,
                'updated_at' => (string) $c->updated_at,
            ])->values()->all();

            return ApiResponse::success($rows, 'Customer names fetched successfully.');
        }

        $customers = $this->customerService->list();
        $balances = $this->ledgerService->balancesForCustomerIds($customers->pluck('id')->all());

        return ApiResponse::success(
            CustomerResponseData::collectionToArray($customers, $balances),
            'Customers fetched successfully.'
        );
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create($request->validated());
        $balance = $this->ledgerService->balance($customer);

        return ApiResponse::created(
            CustomerResponseData::fromModel($customer, $balance)->toArray(),
            'Customer created successfully.'
        );
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        $section = $this->resolveShowSection($request, self::SHOW_SECTIONS, 'summary');
        $balance = $this->ledgerService->balance($customer);
        $base = CustomerResponseData::fromModel($customer, $balance)->toArray();

        if ($section === 'summary') {
            return ApiResponse::success($base, 'Customer fetched successfully.');
        }

        $addresses = CustomerAddressResponseData::collectionToArray(
            $customer->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        );
        $contacts = CustomerContactResponseData::collectionToArray(
            $customer->contacts()->orderBy('name')->get()
        );
        $ledgerPreview = $customer->ledgerEntries()
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($e) => CustomerLedgerEntryResponseData::fromModel($e)->toArray())
            ->values()
            ->all();

        $attachmentsPreview = AttachmentResponseData::collectionToArray(
            $customer->attachments()->orderByDesc('id')->limit(20)->get(),
            fn (Attachment $a): string => route('customers.attachments.download', [
                'customer' => $customer->getKey(),
                'attachment' => $a->getKey(),
            ])
        );

        return ApiResponse::success(
            array_merge($base, [
                'addresses' => $addresses,
                'contacts' => $contacts,
                'ledger_preview' => $ledgerPreview,
                'attachments_preview' => $attachmentsPreview,
            ]),
            'Customer fetched successfully.'
        );
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $customer = $this->customerService->update($customer, $request->validated());
        $balance = $this->ledgerService->balance($customer);

        return ApiResponse::success(
            CustomerResponseData::fromModel($customer, $balance)->toArray(),
            'Customer updated successfully.'
        );
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->delete($customer);

        return ApiResponse::success(null, 'Customer deleted successfully.');
    }
}
