<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ItemVariant\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesListSection;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ItemVariant\Models\ItemAttribute;
use App\Modules\Inventory\ItemVariant\Services\ItemAttributeService;
use App\Modules\Inventory\ItemVariant\Services\ItemVariantService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemVariantController extends Controller
{
    use ResolvesListSection;

    public function __construct(
        private readonly ItemAttributeService $attributes,
        private readonly ItemVariantService $variants,
    ) {}

    /** Paginated table; `?section=names` returns every attribute with its values (variant picker). */
    public function attributesIndex(Request $request): JsonResponse
    {
        $names = $this->namesResponse($request, fn (): array => $this->attributes->list(), 'Item attributes fetched successfully.');
        if ($names) {
            return $names;
        }

        return ListPagination::json(
            $this->attributes->paginateForTable(ListPagination::search($request), ListPagination::perPage($request)),
            fn (ItemAttribute $attribute): array => $this->attributes->toArray($attribute),
            'Item attributes fetched successfully.'
        );
    }

    public function attributesShow(ItemAttribute $itemAttribute): JsonResponse
    {
        return ApiResponse::success($this->attributes->toArray($itemAttribute->load('values')), 'Item attribute fetched successfully.');
    }

    public function attributesStore(Request $request): JsonResponse
    {
        return ApiResponse::created($this->attributes->save(null, $this->validateAttribute($request, null)), 'Item attribute created successfully.');
    }

    public function attributesUpdate(Request $request, ItemAttribute $itemAttribute): JsonResponse
    {
        return ApiResponse::success($this->attributes->save($itemAttribute, $this->validateAttribute($request, $itemAttribute)), 'Item attribute updated successfully.');
    }

    public function attributesDestroy(ItemAttribute $itemAttribute): JsonResponse
    {
        $this->attributes->delete($itemAttribute);

        return ApiResponse::success(null, 'Item attribute deleted successfully.');
    }

    public function index(Item $item): JsonResponse
    {
        return ApiResponse::success($this->variants->show($item), 'Item variants fetched successfully.');
    }

    public function generate(Request $request, Item $item): JsonResponse
    {
        $data = $request->validate([
            'attributes' => ['required', 'array', 'min:1', 'max:3'],
            'attributes.*.attribute_id' => ['required', 'integer', 'exists:item_attributes,id'],
            'attributes.*.value_ids' => ['required', 'array', 'min:1'],
            'attributes.*.value_ids.*' => ['integer', 'distinct'],
        ]);

        return ApiResponse::success($this->variants->generate($item, $data['attributes']), 'Item variants generated successfully.');
    }

    /**
     * @return array{code: string, name: string, sort_order?: int|null, values?: list<array<string, mixed>>}
     */
    private function validateAttribute(Request $request, ?ItemAttribute $attribute): array
    {
        if ($request->has('code')) {
            $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        }

        /** @var array{code: string, name: string, sort_order?: int|null, values?: list<array<string, mixed>>} */
        return $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_]+$/', Rule::unique('item_attributes', 'code')->ignore($attribute?->id)],
            'name' => ['required', 'string', 'max:120'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'values' => ['present', 'array', 'max:100'],
            'values.*.id' => ['nullable', 'integer'],
            'values.*.code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_]+$/', 'distinct:ignore_case'],
            'values.*.name' => ['required', 'string', 'max:120'],
            'values.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
