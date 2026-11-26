<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ScaleBarcode\Http\Requests\PrintScaleLabelsRequest;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleLabelTemplate;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleLabelService;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleLabelTemplateService;
use App\Modules\Inventory\Shared\Enums\DimensionType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Weighed-item label PDFs. */
class ScaleLabelController extends Controller
{
    public function __construct(
        private readonly ScaleLabelService $labels,
        private readonly ScaleLabelTemplateService $templates,
    ) {}

    public function print(PrintScaleLabelsRequest $request): Response
    {
        $data = $request->validated();
        $template = $this->templates->find((int) $data['scale_label_template_id']);
        $this->assertActive($template);

        return $this->labels->render($template, array_values($data['lines']))
            ->stream('scale-labels-'.now()->format('Ymd-His').'.pdf');
    }

    /** One page: the saved or unsaved template, with the given lines or a sample label. */
    public function preview(PrintScaleLabelsRequest $request): Response
    {
        $data = $request->validated();
        $template = isset($data['template'])
            ? $this->templates->make($data['template'])
            : $this->templates->find((int) ($data['scale_label_template_id'] ?? 0));
        $lines = array_values($data['lines'] ?? []);
        if ($lines === []) {
            $lines = $this->labels->sampleLines($template);
        }
        $lines = array_map(fn (array $l): array => [...$l, 'copies' => 1], array_slice($lines, 0, 1));

        return $this->labels->render($template, $lines)->stream('scale-label-preview.pdf');
    }

    /** Items sold at the scale, for the packing-station picker (search by name, code or PLU). */
    public function items(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));
        $items = Item::query()
            ->with(['itemUoms.uom:id,code,name', 'unitGroup:id,dimension_type'])
            ->where('is_scale_item', true)
            ->where('is_active', true)
            ->whereNotNull('plu_code')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'ilike', '%'.$search.'%')
                ->orWhere('item_code', 'ilike', '%'.$search.'%')
                ->orWhere('plu_code', 'like', '%'.ltrim($search, '0').'%')))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(function (Item $item): array {
                $units = $item->itemUoms;
                $uom = $units->firstWhere('is_default_sale', true) ?? $units->firstWhere('is_base', true) ?? $units->first();

                return [
                    'id' => (string) $item->id,
                    'name' => $item->name,
                    'item_code' => $item->item_code,
                    'plu_code' => $item->plu_code,
                    'unit_code' => $uom?->uom?->code,
                    'unit_price' => $uom?->selling_price !== null ? (string) $uom->selling_price : null,
                    'shelf_life_days' => $item->shelf_life_days,
                    'is_weight' => $item->unitGroup?->dimension_type === DimensionType::Weight,
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success($items, 'Scale items fetched successfully.');
    }

    private function assertActive(ScaleLabelTemplate $template): void
    {
        if (! $template->is_active) {
            abort(422, 'This label template is inactive.', ['X-Error-Code' => 'SCALE_LABEL_TEMPLATE_INACTIVE']);
        }
    }
}
