<?php

declare(strict_types=1);

namespace App\Modules\Accounting\FixedAsset\Services;

use App\Modules\Accounting\Enums\AccountRole;
use App\Modules\Accounting\FixedAsset\Enums\DepreciationMethod;
use App\Modules\Accounting\FixedAsset\Enums\DisposalMode;
use App\Modules\Accounting\FixedAsset\Enums\FixedAssetSource;
use App\Modules\Accounting\FixedAsset\Enums\FixedAssetStatus;
use App\Modules\Accounting\FixedAsset\Models\DepreciationLine;
use App\Modules\Accounting\FixedAsset\Models\DepreciationRun;
use App\Modules\Accounting\FixedAsset\Models\FixedAsset;
use App\Modules\Accounting\FixedAsset\Models\FixedAssetCategory;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\FiscalCalendar;
use App\Modules\Accounting\Support\JournalMath;
use App\Modules\Accounting\Support\ReportBranchScope;
use App\Modules\Currency\Models\Currency;
use App\Modules\Customer\Enums\LedgerReferenceType;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Services\CustomerLedgerService;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoice;
use App\Modules\Inventory\Purchasing\Models\PurchaseInvoiceLine;
use App\Modules\Inventory\Purchasing\PurchaseDebitNote\Models\PurchaseDebitNote;
use App\Modules\Reports\Support\PrimaryMoney;
use App\Modules\Treasury\Models\CashBankAccount;
use App\Support\DocumentNumberService;
use App\Support\DocumentType;
use App\Support\ListPagination;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FixedAssetService
{
    public function __construct(
        private readonly DepreciationCalculator $calculator,
        private readonly JournalPostingService $journal,
        private readonly DocumentNumberService $numbers,
        private readonly CustomerLedgerService $customerLedger,
        private readonly FiscalCalendar $fiscal,
        private readonly ReportBranchScope $branchScope,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, FixedAsset>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = FixedAsset::query()->with(['category:id,code,name', 'branch:id,name,shortcut_name'])->orderByDesc('acquisition_date')->orderByDesc('created_at');
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }
        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }
        $requested = ! empty($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $this->filterBranch($query, $this->branchScope->apply($requested)['filters']);
        if (! empty($filters['purchase_invoice_id'])) {
            $lineIds = PurchaseInvoiceLine::query()->where('purchase_invoice_id', (string) $filters['purchase_invoice_id'])->pluck('id')->map(fn ($id): string => (string) $id)->all();
            $query->where('source_type', FixedAssetSource::PurchaseInvoiceLine)->whereIn('source_id', $lineIds !== [] ? $lineIds : ['-']);
        }
        ListPagination::applySearch($query, $filters['search'] ?? null, ['asset_number', 'name', 'serial_number', 'location', 'custodian']);

        return $query->paginate($perPage);
    }

    public function find(string $id): FixedAsset
    {
        return FixedAsset::query()->with([
            'category.assetAccount', 'category.accumulatedAccount', 'category.expenseAccount',
            'branch:id,name,shortcut_name', 'item:id,name,item_code', 'supplier:id,name,supplier_code',
            'depreciationLines.run',
        ])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): FixedAsset
    {
        $category = $this->activeCategory((int) $data['category_id']);
        $attributes = $this->draftAttributes($data, $category);
        $this->assertSalvage((string) $attributes['cost'], (string) $attributes['salvage_value']);
        $this->assertOpening((string) $attributes['cost'], (string) $attributes['opening_accumulated']);

        $asset = FixedAsset::query()->create([
            ...$attributes,
            'source_type' => FixedAssetSource::Manual,
            'source_id' => null,
            'status' => FixedAssetStatus::Draft,
            'accumulated_depreciation' => '0',
            'created_by' => $userId,
        ]);

        return $this->find((string) $asset->id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(FixedAsset $asset, array $data): FixedAsset
    {
        if ($asset->status !== FixedAssetStatus::Draft) {
            $asset->update(array_intersect_key($data, array_flip(['name', 'location', 'custodian', 'serial_number'])));

            return $this->find((string) $asset->id);
        }

        $category = $this->activeCategory((int) ($data['category_id'] ?? $asset->category_id));
        $attributes = $this->draftAttributes($data, $category, $asset);
        $this->assertSalvage((string) $attributes['cost'], (string) $attributes['salvage_value']);
        $this->assertOpening((string) $attributes['cost'], (string) $attributes['opening_accumulated']);
        $asset->update($attributes);

        return $this->find((string) $asset->id);
    }

    public function delete(FixedAsset $asset): void
    {
        if ($asset->status !== FixedAssetStatus::Draft) {
            $this->fail('Only a draft asset can be deleted.', 'FIXED_ASSET_NOT_DRAFT');
        }
        $asset->delete();
    }

    public function activate(FixedAsset $asset, ?string $userId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $userId): FixedAsset {
            $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FixedAssetStatus::Draft) {
                $this->fail('Only a draft asset can be activated.', 'FIXED_ASSET_NOT_DRAFT');
            }
            $this->assertSalvage((string) $locked->cost, (string) $locked->salvage_value);
            $this->assertOpening((string) $locked->cost, (string) $locked->opening_accumulated);

            $through = Carbon::parse($this->fiscal->today())->startOfMonth()->subMonth()->endOfMonth()->toDateString();
            $locked->accumulated_depreciation = JournalMath::store($locked->opening_accumulated);
            $locked->depreciated_through = Carbon::parse($through);
            $locked->status = $this->reachedSalvage($locked) ? FixedAssetStatus::FullyDepreciated : FixedAssetStatus::Active;
            $locked->posted_by = $userId;
            $locked->posted_at = now();
            $locked->save();

            $this->numbers->assign($locked, 'asset_number', DocumentType::FixedAsset, (int) $locked->branch_id, $this->fiscal->today());
            $this->journal->fixedAssetOpening($locked->fresh(['category.assetAccount', 'category.accumulatedAccount']) ?? $locked, $userId);

            return $this->find((string) $locked->id);
        });
    }

    /**
     * @param  array{useful_life_months?: int, salvage_value?: string}  $changes
     */
    public function changeEstimate(FixedAsset $asset, array $changes): FixedAsset
    {
        if (! in_array($asset->status, [FixedAssetStatus::Active, FixedAssetStatus::FullyDepreciated], true)) {
            $this->fail('Only an asset in service can change its estimate.', 'FIXED_ASSET_NOT_ACTIVE');
        }
        $life = array_key_exists('useful_life_months', $changes) ? (int) $changes['useful_life_months'] : (int) $asset->useful_life_months;
        $salvage = array_key_exists('salvage_value', $changes) ? (string) $changes['salvage_value'] : (string) $asset->salvage_value;
        $this->assertSalvage((string) $asset->cost, $salvage);
        $book = JournalMath::sub((string) $asset->cost, (string) $asset->accumulated_depreciation);
        if (JournalMath::compare($salvage, $book) > 0) {
            $this->fail('Salvage cannot be higher than the book value.', 'FIXED_ASSET_SALVAGE_TOO_HIGH');
        }

        $asset->useful_life_months = max(1, $life);
        $asset->salvage_value = JournalMath::store($salvage);
        $asset->status = JournalMath::compare($book, $salvage) <= 0 ? FixedAssetStatus::FullyDepreciated : FixedAssetStatus::Active;
        $asset->save();

        return $this->find((string) $asset->id);
    }

    /**
     * @param  array{date: string, amount: string, mode: string, customer_id?: string, cash_bank_account_id?: int}  $data
     */
    public function dispose(FixedAsset $asset, array $data, ?string $userId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $userId): FixedAsset {
            $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [FixedAssetStatus::Active, FixedAssetStatus::FullyDepreciated], true)) {
                $this->fail('Only an asset in service can be disposed.', 'FIXED_ASSET_NOT_ACTIVE');
            }
            $mode = DisposalMode::from((string) $data['mode']);
            $date = Carbon::parse((string) $data['date'])->toDateString();
            if ($date > $this->fiscal->today()) {
                $this->fail('The disposal date cannot be in the future.', 'FIXED_ASSET_DISPOSAL_DATE');
            }
            if ($locked->depreciated_through !== null && $locked->depreciated_through->startOfMonth()->gt(Carbon::parse($date)->startOfMonth())) {
                $this->fail('Depreciation is already posted after this date.', 'FIXED_ASSET_DISPOSAL_DATE');
            }
            $proceeds = $mode === DisposalMode::Scrap ? '0' : JournalMath::store($data['amount'] ?? '0');
            if (JournalMath::isNegative($proceeds)) {
                $this->fail('The sale amount cannot be negative.', 'FIXED_ASSET_DISPOSAL_AMOUNT');
            }

            $profile = $this->profile($locked);
            $catchUp = $this->calculator->catchUp($profile, $date);
            $priorThrough = $locked->depreciated_through?->copy();
            $locked->accumulated_depreciation = JournalMath::store(JournalMath::add((string) $locked->accumulated_depreciation, $catchUp));
            $locked->depreciated_through = Carbon::parse($date)->endOfMonth();
            $locked->disposal_catch_up = JournalMath::store($catchUp);
            $locked->disposal_prior_through = $priorThrough;
            $locked->disposal_amount = JournalMath::store($proceeds);
            $locked->disposed_at = Carbon::parse($date);
            $locked->status = FixedAssetStatus::Disposed;
            $locked->save();

            $this->numbers->assign($locked, 'disposal_number', DocumentType::AssetDisposal, (int) $locked->branch_id, $date);
            [$settlement, $partyType, $partyId] = $this->settlement($mode, $data, $proceeds);
            $entry = $this->journal->fixedAssetDisposal($locked->fresh(['category']) ?? $locked, $date, $catchUp, $proceeds, $settlement, $partyType, $partyId, $userId);
            if ($entry !== null) {
                $locked->disposal_entry_id = $entry->id;
                $locked->save();
            }
            if ($mode === DisposalMode::SaleOnAccount && JournalMath::isPositive($proceeds)) {
                $customer = Customer::query()->findOrFail((string) $data['customer_id']);
                $primary = Currency::getPrimary();
                if ($primary === null) {
                    $this->fail('A primary currency is required.', 'CURRENCY_PRIMARY_REQUIRED');
                }
                $this->customerLedger->postEntry($customer, (int) $primary->id, $proceeds, '0', LedgerReferenceType::FixedAssetDisposal, (string) $locked->id, $date);
            }

            return $this->find((string) $locked->id);
        });
    }

    public function reverseDisposal(FixedAsset $asset, ?string $userId): FixedAsset
    {
        return DB::transaction(function () use ($asset, $userId): FixedAsset {
            $locked = FixedAsset::query()->whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== FixedAssetStatus::Disposed || $locked->disposed_at === null) {
                $this->fail('This asset has no disposal to reverse.', 'FIXED_ASSET_NOT_DISPOSED');
            }
            $later = DepreciationRun::query()
                ->where('branch_id', $locked->branch_id)
                ->where('status', 'posted')
                ->whereDate('period_month', '>', $locked->disposed_at->copy()->startOfMonth()->toDateString())
                ->exists();
            if ($later) {
                $this->fail('A later depreciation run is posted. Undo that run first.', 'FIXED_ASSET_LATER_DEPRECIATION');
            }

            $this->journal->reverse(FixedAsset::DISPOSAL_REFERENCE, (string) $locked->id, $userId, $locked->disposed_at->toDateString());
            if (JournalMath::isPositive((string) $locked->disposal_amount)) {
                $entry = $locked->disposal_entry_id !== null
                    ? JournalEntry::query()->with('lines')->find($locked->disposal_entry_id)
                    : null;
                $party = $entry?->lines->first(fn (JournalEntryLine $line): bool => $line->party_type === JournalEntryLine::PARTY_CUSTOMER && $line->party_id !== null);
                if ($party !== null) {
                    $customer = Customer::query()->find((string) $party->party_id);
                    $primary = Currency::getPrimary();
                    if ($customer !== null && $primary !== null) {
                        $this->customerLedger->postEntry($customer, (int) $primary->id, '0', JournalMath::store($locked->disposal_amount), LedgerReferenceType::FixedAssetDisposal, (string) $locked->id, $locked->disposed_at->toDateString());
                    }
                }
            }

            $locked->accumulated_depreciation = JournalMath::store(JournalMath::sub((string) $locked->accumulated_depreciation, (string) $locked->disposal_catch_up));
            $locked->depreciated_through = $locked->disposal_prior_through?->copy();
            $locked->status = $this->reachedSalvage($locked) ? FixedAssetStatus::FullyDepreciated : FixedAssetStatus::Active;
            $locked->disposed_at = null;
            $locked->disposal_amount = null;
            $locked->disposal_catch_up = '0';
            $locked->disposal_prior_through = null;
            $locked->disposal_number = null;
            $locked->disposal_entry_id = null;
            $locked->save();

            return $this->find((string) $locked->id);
        });
    }

    public function createFromInvoice(PurchaseInvoice $invoice, ?string $userId): void
    {
        if ($invoice->is_opening) {
            return;
        }
        $lines = PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->id)->with('item')->orderBy('id')->get();
        foreach ($lines as $line) {
            $item = $line->item;
            if ($item === null || ! $item->is_fixed_asset) {
                continue;
            }
            $category = FixedAssetCategory::query()->find((int) $item->fixed_asset_category_id);
            if ($category === null) {
                $this->fail('This fixed-asset item has no category.', 'FIXED_ASSET_ACCOUNT_INVALID');
            }
            foreach ($this->unitCosts($line, $invoice) as $cost) {
                $asset = new FixedAsset([
                    'branch_id' => (int) $invoice->branch_id,
                    'name' => mb_substr((string) $item->name, 0, 255),
                    'category_id' => (int) $category->id,
                    'item_id' => (string) $item->id,
                    'source_type' => FixedAssetSource::PurchaseInvoiceLine,
                    'source_id' => (string) $line->id,
                    'supplier_id' => (string) $invoice->supplier_id,
                    'acquisition_date' => $invoice->invoice_date->toDateString(),
                    'in_service_date' => $invoice->invoice_date->toDateString(),
                    'cost' => $cost,
                    'salvage_value' => $this->salvageFromPercent($cost, (string) $category->salvage_percent),
                    'method' => $category->method,
                    'useful_life_months' => (int) $category->useful_life_months,
                    'declining_rate' => $category->declining_rate,
                    'prorate_first_month' => (bool) $category->prorate_first_month,
                    'opening_accumulated' => '0',
                    'accumulated_depreciation' => '0',
                    'status' => FixedAssetStatus::Active,
                    'created_by' => $userId,
                    'posted_by' => $userId,
                    'posted_at' => now(),
                ]);
                $asset->depreciated_through = $this->calculator->firstMonth($this->profile($asset))->subMonth()->endOfMonth();
                if ($this->reachedSalvage($asset)) {
                    $asset->status = FixedAssetStatus::FullyDepreciated;
                }
                $asset->save();
                $this->numbers->assign($asset, 'asset_number', DocumentType::FixedAsset, (int) $asset->branch_id, $invoice->invoice_date);
            }
        }
    }

    public function assertInvoiceReversible(PurchaseInvoice $invoice): void
    {
        $lineIds = PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->id)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($lineIds === []) {
            return;
        }
        $blocked = FixedAsset::query()
            ->where('source_type', FixedAssetSource::PurchaseInvoiceLine)
            ->whereIn('source_id', $lineIds)
            ->where(function ($query): void {
                $query->where('status', FixedAssetStatus::Disposed)
                    ->orWhere('accumulated_depreciation', '>', 0)
                    ->orWhereHas('depreciationLines');
            })
            ->exists();
        if ($blocked) {
            $this->fail('A fixed asset from this invoice has depreciation or a disposal.', 'FIXED_ASSET_HAS_DEPRECIATION');
        }
    }

    public function deleteForInvoice(PurchaseInvoice $invoice): void
    {
        $lineIds = PurchaseInvoiceLine::query()->where('purchase_invoice_id', $invoice->id)->pluck('id')->map(fn ($id): string => (string) $id)->all();
        if ($lineIds === []) {
            return;
        }
        FixedAsset::query()
            ->where('source_type', FixedAssetSource::PurchaseInvoiceLine)
            ->whereIn('source_id', $lineIds)
            ->delete();
    }

    public function assertDebitNoteHasNoAsset(PurchaseDebitNote $note): void
    {
        $note->loadMissing('lines.item');
        foreach ($note->lines as $line) {
            if ($line->item?->is_fixed_asset) {
                $this->fail('Return the asset by disposing of it. A debit note cannot return a fixed asset.', 'FIXED_ASSET_RETURN_NOT_SUPPORTED');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function listRow(FixedAsset $asset): array
    {
        $book = JournalMath::store(JournalMath::sub((string) $asset->cost, (string) $asset->accumulated_depreciation));

        return [
            'id' => (string) $asset->id,
            'asset_number' => $asset->asset_number,
            'name' => (string) $asset->name,
            'status' => $asset->status->value,
            'category_id' => (int) $asset->category_id,
            'category' => $asset->category !== null ? ['id' => (int) $asset->category->id, 'code' => (string) $asset->category->code, 'name' => (string) $asset->category->name] : null,
            'branch_id' => (int) $asset->branch_id,
            'branch' => $asset->branch !== null ? ['id' => (int) $asset->branch->id, 'code' => (string) $asset->branch->shortcut_name, 'name' => (string) $asset->branch->name] : null,
            'in_service_date' => $asset->in_service_date->toDateString(),
            'cost' => JournalMath::store($asset->cost),
            'accumulated_depreciation' => JournalMath::store($asset->accumulated_depreciation),
            'book_value' => $book,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(FixedAsset $asset): array
    {
        $profile = $this->profile($asset);
        $schedule = in_array($asset->status, [FixedAssetStatus::Active, FixedAssetStatus::Draft], true)
            ? $this->calculator->schedule($profile)
            : [];
        $suggested = null;
        if ($asset->status === FixedAssetStatus::Draft) {
            $goLive = Carbon::parse($this->fiscal->today())->startOfMonth()->subMonth()->endOfMonth()->toDateString();
            $suggested = $this->calculator->catchUp($profile, $goLive);
        }

        return [
            ...$this->listRow($asset),
            'serial_number' => $asset->serial_number,
            'location' => $asset->location,
            'custodian' => $asset->custodian,
            'acquisition_date' => $asset->acquisition_date->toDateString(),
            'salvage_value' => JournalMath::store($asset->salvage_value),
            'method' => $asset->method->value,
            'useful_life_months' => (int) $asset->useful_life_months,
            'declining_rate' => $asset->declining_rate !== null ? (string) $asset->declining_rate : null,
            'prorate_first_month' => (bool) $asset->prorate_first_month,
            'opening_accumulated' => JournalMath::store($asset->opening_accumulated),
            'depreciated_through' => $asset->depreciated_through?->toDateString(),
            'suggested_opening' => $suggested,
            'source_type' => $asset->source_type->value,
            'source' => $this->source($asset),
            'item' => $asset->item !== null ? ['id' => (string) $asset->item->id, 'name' => (string) $asset->item->name, 'item_code' => $asset->item->item_code] : null,
            'supplier' => $asset->supplier !== null ? ['id' => (string) $asset->supplier->id, 'name' => (string) $asset->supplier->name, 'code' => $asset->supplier->supplier_code] : null,
            'disposed_at' => $asset->disposed_at?->toDateString(),
            'disposal_amount' => $asset->disposal_amount !== null ? JournalMath::store($asset->disposal_amount) : null,
            'disposal_number' => $asset->disposal_number,
            'disposal_entry_id' => $asset->disposal_entry_id,
            'history' => $this->history($asset),
            'schedule' => $schedule,
        ];
    }

    public function profile(FixedAsset $asset): DepreciationProfile
    {
        return new DepreciationProfile(
            cost: (string) $asset->cost,
            salvageValue: (string) $asset->salvage_value,
            accumulatedDepreciation: (string) $asset->accumulated_depreciation,
            method: $asset->method,
            usefulLifeMonths: (int) $asset->useful_life_months,
            decliningRate: $asset->declining_rate !== null ? (string) $asset->declining_rate : null,
            prorateFirstMonth: (bool) $asset->prorate_first_month,
            inServiceDate: $asset->in_service_date->toDateString(),
            depreciatedThrough: $asset->depreciated_through?->toDateString(),
            scale: JournalMath::scale(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function draftAttributes(array $data, FixedAssetCategory $category, ?FixedAsset $current = null): array
    {
        $cost = JournalMath::store($data['cost'] ?? $current?->cost ?? '0');
        $method = isset($data['method']) ? DepreciationMethod::from((string) $data['method']) : ($current?->method ?? $category->method);
        $life = isset($data['useful_life_months']) ? (int) $data['useful_life_months'] : (int) ($current?->useful_life_months ?? $category->useful_life_months);
        $rate = array_key_exists('declining_rate', $data)
            ? ($data['declining_rate'] !== null && $data['declining_rate'] !== '' ? (string) $data['declining_rate'] : null)
            : ($current?->declining_rate ?? $category->declining_rate);
        $salvage = array_key_exists('salvage_value', $data)
            ? JournalMath::store($data['salvage_value'])
            : ($current !== null ? JournalMath::store($current->salvage_value) : $this->salvageFromPercent($cost, (string) $category->salvage_percent));
        $opening = JournalMath::store($data['opening_accumulated'] ?? $current?->opening_accumulated ?? '0');

        return [
            // New assets take the user's active branch (DocumentBranchResolver); edits keep the branch.
            'branch_id' => $current?->branch_id,
            'name' => (string) ($data['name'] ?? $current?->name),
            'category_id' => (int) $category->id,
            'item_id' => array_key_exists('item_id', $data) ? $data['item_id'] : $current?->item_id,
            'supplier_id' => array_key_exists('supplier_id', $data) ? $data['supplier_id'] : $current?->supplier_id,
            'serial_number' => array_key_exists('serial_number', $data) ? $data['serial_number'] : $current?->serial_number,
            'location' => array_key_exists('location', $data) ? $data['location'] : $current?->location,
            'custodian' => array_key_exists('custodian', $data) ? $data['custodian'] : $current?->custodian,
            'acquisition_date' => (string) ($data['acquisition_date'] ?? $current?->acquisition_date?->toDateString()),
            'in_service_date' => (string) ($data['in_service_date'] ?? $current?->in_service_date?->toDateString()),
            'cost' => $cost,
            'salvage_value' => $salvage,
            'method' => $method,
            'useful_life_months' => max(1, (int) $life),
            'declining_rate' => $rate,
            'prorate_first_month' => (bool) $category->prorate_first_month,
            'opening_accumulated' => $opening,
        ];
    }

    /**
     * @return list<string>
     */
    private function unitCosts(PurchaseInvoiceLine $line, PurchaseInvoice $invoice): array
    {
        $quantity = bcadd((string) $line->quantity, '0', 6);
        if (bccomp($quantity, '0', 6) <= 0) {
            return [];
        }
        $linePrimary = PrimaryMoney::convert((string) $line->line_subtotal, (string) $invoice->exchange_rate);
        $whole = preg_match('/^\d+(\.0+)?$/', $quantity) === 1;
        if (! $whole) {
            return [JournalMath::store($linePrimary)];
        }
        $units = (int) $quantity;
        if ($units > 500) {
            $this->fail('A fixed-asset line cannot create more than 500 assets.', 'FIXED_ASSET_QUANTITY_TOO_LARGE');
        }
        if ($units === 1) {
            return [JournalMath::store($linePrimary)];
        }
        $each = JournalMath::round(bcdiv(JournalMath::of($linePrimary), (string) $units, JournalMath::WORK_SCALE));
        $costs = [];
        $running = '0';
        for ($i = 1; $i < $units; $i++) {
            $costs[] = JournalMath::store($each);
            $running = JournalMath::add($running, $each);
        }
        $costs[] = JournalMath::store(JournalMath::sub($linePrimary, $running));

        return $costs;
    }

    /**
     * @param  array{date: string, amount: string, mode: string, customer_id?: string, cash_bank_account_id?: int}  $data
     * @return array{0: ?Account, 1: ?string, 2: ?string}
     */
    private function settlement(DisposalMode $mode, array $data, string $proceeds): array
    {
        if (! JournalMath::isPositive($proceeds) || $mode === DisposalMode::Scrap) {
            return [null, null, null];
        }
        if ($mode === DisposalMode::SaleOnAccount) {
            $customerId = (string) ($data['customer_id'] ?? '');
            if ($customerId === '' || ! Customer::query()->whereKey($customerId)->exists()) {
                $this->fail('Choose the customer who is buying the asset.', 'FIXED_ASSET_CUSTOMER_REQUIRED');
            }

            return [$this->journal->mappedAccount(AccountRole::Receivable), JournalEntryLine::PARTY_CUSTOMER, $customerId];
        }

        $cashBank = CashBankAccount::query()->with('account')->find((int) ($data['cash_bank_account_id'] ?? 0));
        $primary = Currency::getPrimary();
        if ($cashBank === null || ! $cashBank->is_active || $cashBank->account === null || ! $cashBank->account->isPostable()) {
            $this->fail('Choose an active cash or bank account.', 'FIXED_ASSET_CASH_ACCOUNT_INVALID');
        }
        if ($primary !== null && (int) $cashBank->currency_id !== (int) $primary->id) {
            $this->fail('The cash or bank account must be in the primary currency.', 'FIXED_ASSET_CASH_ACCOUNT_INVALID');
        }

        return [$cashBank->account, null, null];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function source(FixedAsset $asset): ?array
    {
        if ($asset->source_type !== FixedAssetSource::PurchaseInvoiceLine || $asset->source_id === null) {
            return ['type' => $asset->source_type->value, 'id' => $asset->source_id];
        }
        $line = PurchaseInvoiceLine::query()->with('invoice:id,invoice_number')->find((int) $asset->source_id);

        return [
            'type' => $asset->source_type->value,
            'id' => $asset->source_id,
            'purchase_invoice_id' => $line?->purchase_invoice_id,
            'invoice_number' => $line?->invoice?->invoice_number,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(FixedAsset $asset): array
    {
        return $asset->depreciationLines
            ->sortBy(fn (DepreciationLine $line) => $line->run?->period_month?->toDateString().$line->id)
            ->map(fn (DepreciationLine $line): array => [
                'month' => $line->run?->period_month?->toDateString(),
                'amount' => JournalMath::store($line->amount),
                'book_value_after' => JournalMath::store($line->book_value_after),
                'run_id' => (string) $line->run_id,
            ])->values()->all();
    }

    private function activeCategory(int $id): FixedAssetCategory
    {
        $category = FixedAssetCategory::query()->whereKey($id)->where('is_active', true)->first();
        if ($category === null) {
            $this->fail('Choose an active asset category.', 'FIXED_ASSET_CATEGORY_INVALID');
        }

        return $category;
    }

    private function salvageFromPercent(string $cost, string $percent): string
    {
        return JournalMath::store(JournalMath::round(bcdiv(bcmul(JournalMath::of($cost), JournalMath::of($percent), JournalMath::WORK_SCALE), '100', JournalMath::WORK_SCALE)));
    }

    private function reachedSalvage(FixedAsset $asset): bool
    {
        $book = JournalMath::sub((string) $asset->cost, (string) $asset->accumulated_depreciation);

        return JournalMath::compare($book, (string) $asset->salvage_value) <= 0;
    }

    private function assertSalvage(string $cost, string $salvage): void
    {
        if (JournalMath::compare($salvage, $cost) > 0) {
            $this->fail('Salvage cannot be higher than cost.', 'FIXED_ASSET_SALVAGE_TOO_HIGH');
        }
    }

    private function assertOpening(string $cost, string $opening): void
    {
        if (JournalMath::compare($opening, $cost) > 0) {
            $this->fail('Depreciation already taken cannot be higher than cost.', 'FIXED_ASSET_OPENING_TOO_HIGH');
        }
    }

    private function fail(string $message, string $code): never
    {
        abort(422, $message, ['X-Error-Code' => $code]);
    }

    /**
     * One branch, or the branches the user may see (ReportBranchScope filters).
     *
     * @param  Builder<FixedAsset>  $query
     * @param  array<string, mixed>  $filters
     */
    private function filterBranch(Builder $query, array $filters): void
    {
        if (array_key_exists('branch_id', $filters)) {
            $query->where('branch_id', (int) $filters['branch_id']);
        } elseif (array_key_exists('branch_ids', $filters)) {
            $ids = (array) $filters['branch_ids'];
            $ids === [] ? $query->whereRaw('1 = 0') : $query->whereIn('branch_id', $ids);
        }
    }
}
