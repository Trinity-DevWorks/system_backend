<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Concerns\ResolvesShowSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Currency\Models\Currency;
use App\Modules\Supplier\DTOs\SupplierAddressResponseData;
use App\Modules\Supplier\DTOs\SupplierContactResponseData;
use App\Modules\Supplier\DTOs\SupplierLedgerEntryResponseData;
use App\Modules\Supplier\DTOs\SupplierResponseData;
use App\Modules\Supplier\DTOs\SupplierTableRowResponseData;
use App\Modules\Supplier\Http\Requests\StoreSupplierRequest;
use App\Modules\Supplier\Http\Requests\UpdateSupplierRequest;
use App\Modules\Supplier\Models\Supplier;
use App\Modules\Supplier\Services\SupplierLedgerService;
use App\Modules\Supplier\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    use ResolvesListSection;
    use ResolvesShowSection;

    private const INDEX_SECTIONS = ['names'];

    private const SHOW_SECTIONS = ['summary', 'full'];

    public function __construct(
        private readonly SupplierService $supplierService,
        private readonly SupplierLedgerService $ledgerService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($this->resolveListSection($request, self::INDEX_SECTIONS) === 'names') {
            $rows = $this->supplierService->names()->map(fn (Supplier $s): array => [
                'id' => $s->id,
                'supplier_code' => (string) ($s->supplier_code ?? ''),
                'name' => $s->name,
                'is_active' => (bool) $s->is_active,
                'created_at' => (string) $s->created_at,
                'updated_at' => (string) $s->updated_at,
            ])->values()->all();

            return ApiResponse::success($rows, 'Supplier names fetched successfully.');
        }

        $suppliers = $this->supplierService->listForTable();

        return ApiResponse::success(
            $suppliers
                ->map(fn (Supplier $s): array => SupplierTableRowResponseData::fromModel($s)->toArray())
                ->values()
                ->all(),
            'Suppliers fetched successfully.'
        );
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->supplierService->create($request->validated());
        $supplier->loadMissing(['balances.currency', 'supplierGroup', 'paymentMethod', 'paymentTerm', 'vatGroup']);
        $ledgerBy = $this->ledgerService->balancesPerCurrencyForSupplier($supplier);

        return ApiResponse::created(
            SupplierResponseData::fromModel($supplier, $ledgerBy, Currency::getPrimary()?->id)->toArray(),
            'Supplier created successfully.'
        );
    }

    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        $section = $this->resolveShowSection($request, self::SHOW_SECTIONS, 'summary');
        $supplier->loadMissing(['balances.currency', 'supplierGroup', 'paymentMethod', 'paymentTerm', 'vatGroup']);
        $ledgerBy = $this->ledgerService->balancesPerCurrencyForSupplier($supplier);
        $base = SupplierResponseData::fromModel($supplier, $ledgerBy, Currency::getPrimary()?->id)->toArray();

        if ($section === 'summary') {
            return ApiResponse::success($base, 'Supplier fetched successfully.');
        }

        $addresses = SupplierAddressResponseData::collectionToArray(
            $supplier->addresses()->orderByDesc('is_default')->orderBy('id')->get()
        );
        $contacts = SupplierContactResponseData::collectionToArray(
            $supplier->contacts()->orderBy('name')->get()
        );
        $ledgerPreview = $supplier->ledgerEntries()
            ->with('currency:id,code')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn ($e) => SupplierLedgerEntryResponseData::fromModel($e)->toArray())
            ->values()
            ->all();

        $attachmentsPreview = AttachmentResponseData::collectionToArray(
            $supplier->attachments()->orderByDesc('id')->limit(20)->get(),
            fn (Attachment $a): string => route('suppliers.attachments.download', [
                'supplier' => $supplier->getKey(),
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
            'Supplier fetched successfully.'
        );
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $supplier = $this->supplierService->update($supplier, $request->validated());
        $supplier->loadMissing(['balances.currency', 'supplierGroup', 'paymentMethod', 'paymentTerm', 'vatGroup']);
        $ledgerBy = $this->ledgerService->balancesPerCurrencyForSupplier($supplier);

        return ApiResponse::success(
            SupplierResponseData::fromModel($supplier, $ledgerBy, Currency::getPrimary()?->id)->toArray(),
            'Supplier updated successfully.'
        );
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->supplierService->delete($supplier);

        return ApiResponse::success(null, 'Supplier deleted successfully.');
    }
}
