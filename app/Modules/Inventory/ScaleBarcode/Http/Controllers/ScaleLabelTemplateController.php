<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\ScaleBarcode\Http\Requests\ScaleLabelTemplateRequest;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleLabelTemplate;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleLabelTemplateService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScaleLabelTemplateController extends Controller
{
    public function __construct(
        private readonly ScaleLabelTemplateService $templates,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->templates->paginate([
                'search' => ListPagination::search($request),
                'active' => $request->query('active'),
            ], ListPagination::perPage($request)),
            fn (ScaleLabelTemplate $template): array => $this->templates->present($template),
            'Scale label templates fetched successfully.',
        );
    }

    public function show(ScaleLabelTemplate $scaleLabelTemplate): JsonResponse
    {
        return ApiResponse::success($this->templates->present($this->templates->find((int) $scaleLabelTemplate->id)), 'Scale label template fetched successfully.');
    }

    public function store(ScaleLabelTemplateRequest $request): JsonResponse
    {
        return ApiResponse::success($this->templates->present($this->templates->create($request->validated())), 'Scale label template created successfully.', 201);
    }

    public function update(ScaleLabelTemplateRequest $request, ScaleLabelTemplate $scaleLabelTemplate): JsonResponse
    {
        return ApiResponse::success($this->templates->present($this->templates->update($scaleLabelTemplate, $request->validated())), 'Scale label template updated successfully.');
    }

    public function destroy(ScaleLabelTemplate $scaleLabelTemplate): JsonResponse
    {
        $this->templates->delete($scaleLabelTemplate);

        return ApiResponse::success(null, 'Scale label template deleted successfully.');
    }
}
