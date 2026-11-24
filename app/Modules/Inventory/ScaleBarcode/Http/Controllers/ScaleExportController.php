<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleExport;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleExportService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Export history and the "scale prices out of date" counts. */
class ScaleExportController extends Controller
{
    public function __construct(
        private readonly ScaleExportService $exports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ScaleExport::query()->with(['template:id,name', 'branch:id,name', 'user:id,name'])->latest('id');
        if ($request->filled('template_id')) {
            $query->where('scale_export_template_id', (int) $request->query('template_id'));
        }

        return ListPagination::json(
            $query->paginate(ListPagination::perPage($request)),
            fn (ScaleExport $export): array => $this->present($export),
            'Scale exports fetched successfully.',
        );
    }

    public function show(ScaleExport $scaleExport): JsonResponse
    {
        $scaleExport->load(['template:id,name', 'branch:id,name', 'user:id,name']);
        $items = Item::query()
            ->whereIn('id', (array) $scaleExport->item_ids)
            ->orderBy('name')
            ->get(['id', 'name', 'item_code', 'plu_code'])
            ->map(fn (Item $item): array => [
                'id' => (string) $item->id,
                'name' => $item->name,
                'item_code' => $item->item_code,
                'plu_code' => $item->plu_code,
            ])->values()->all();

        return ApiResponse::success([...$this->present($scaleExport), 'items' => $items], 'Scale export fetched successfully.');
    }

    public function outdated(): JsonResponse
    {
        return ApiResponse::success($this->exports->outdated(), 'Scale export status fetched successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ScaleExport $export): array
    {
        return [
            'id' => (int) $export->id,
            'template' => $export->template ? ['id' => (int) $export->template->id, 'name' => $export->template->name] : null,
            'branch' => $export->branch ? ['id' => (int) $export->branch->id, 'name' => $export->branch->name] : null,
            'user' => $export->user ? ['id' => (string) $export->user->id, 'name' => $export->user->name] : null,
            'only_changed' => $export->only_changed,
            'item_count' => $export->item_count,
            'file_name' => $export->file_name,
            'created_at' => $export->created_at->toIso8601String(),
        ];
    }
}
