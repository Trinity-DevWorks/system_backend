<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\ScaleBarcode\Http\Requests\ScaleExportTemplateRequest;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleExportTemplate;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleExportService;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleExportTemplateService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ScaleExportTemplateController extends Controller
{
    public function __construct(
        private readonly ScaleExportTemplateService $templates,
        private readonly ScaleExportService $exports,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->templates->paginate([
                'search' => ListPagination::search($request),
                'active' => $request->query('active'),
            ], ListPagination::perPage($request)),
            fn (ScaleExportTemplate $template): array => $this->templates->present($template),
            'Scale export templates fetched successfully.',
        );
    }

    public function show(ScaleExportTemplate $scaleExportTemplate): JsonResponse
    {
        return ApiResponse::success($this->templates->present($this->templates->find((int) $scaleExportTemplate->id)), 'Scale export template fetched successfully.');
    }

    public function store(ScaleExportTemplateRequest $request): JsonResponse
    {
        return ApiResponse::success($this->templates->present($this->templates->create($request->validated())), 'Scale export template created successfully.', 201);
    }

    public function update(ScaleExportTemplateRequest $request, ScaleExportTemplate $scaleExportTemplate): JsonResponse
    {
        return ApiResponse::success($this->templates->present($this->templates->update($scaleExportTemplate, $request->validated())), 'Scale export template updated successfully.');
    }

    public function destroy(ScaleExportTemplate $scaleExportTemplate): JsonResponse
    {
        $this->templates->delete($scaleExportTemplate);

        return ApiResponse::success(null, 'Scale export template deleted successfully.');
    }

    /** First rows of the file the (unsaved) form would write. */
    public function previewDraft(ScaleExportTemplateRequest $request): JsonResponse
    {
        return ApiResponse::success($this->exports->preview($this->templates->make($request->validated()), false), 'Scale export preview.');
    }

    public function preview(Request $request, ScaleExportTemplate $scaleExportTemplate): JsonResponse
    {
        return ApiResponse::success(
            $this->exports->preview($scaleExportTemplate, $request->boolean('only_changed')),
            'Scale export preview.',
        );
    }

    public function download(Request $request, ScaleExportTemplate $scaleExportTemplate): Response
    {
        $userId = $request->user()?->getAuthIdentifier();

        return $this->exports->download($scaleExportTemplate, $request->boolean('only_changed'), $userId !== null ? (string) $userId : null);
    }
}
