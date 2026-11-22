<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ScaleBarcode\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\Inventory\ScaleBarcode\Http\Requests\ScaleBarcodeFormatRequest;
use App\Modules\Inventory\ScaleBarcode\Http\Requests\TestScaleBarcodeRequest;
use App\Modules\Inventory\ScaleBarcode\Models\ScaleBarcodeFormat;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleBarcodeFormatService;
use App\Modules\Inventory\ScaleBarcode\Services\ScaleBarcodeService;
use App\Support\ListPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScaleBarcodeFormatController extends Controller
{
    public function __construct(
        private readonly ScaleBarcodeFormatService $formats,
        private readonly ScaleBarcodeService $scale,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ListPagination::json(
            $this->formats->paginate([
                'search' => ListPagination::search($request),
                'active' => $request->query('active'),
            ], ListPagination::perPage($request)),
            fn (ScaleBarcodeFormat $format): array => $this->formats->present($format),
            'Scale barcode formats fetched successfully.',
        );
    }

    public function show(ScaleBarcodeFormat $scaleBarcodeFormat): JsonResponse
    {
        return ApiResponse::success($this->formats->present($this->formats->find((int) $scaleBarcodeFormat->id)), 'Scale barcode format fetched successfully.');
    }

    public function store(ScaleBarcodeFormatRequest $request): JsonResponse
    {
        $format = $this->formats->create($request->validated());

        return ApiResponse::success($this->formats->present($format), 'Scale barcode format created successfully.', 201);
    }

    public function update(ScaleBarcodeFormatRequest $request, ScaleBarcodeFormat $scaleBarcodeFormat): JsonResponse
    {
        $format = $this->formats->update($scaleBarcodeFormat, $request->validated());

        return ApiResponse::success($this->formats->present($format), 'Scale barcode format updated successfully.');
    }

    public function destroy(ScaleBarcodeFormat $scaleBarcodeFormat): JsonResponse
    {
        $this->formats->delete($scaleBarcodeFormat);

        return ApiResponse::success(null, 'Scale barcode format deleted successfully.');
    }

    /** Try a barcode against the (unsaved) form: what would the line be? */
    public function test(TestScaleBarcodeRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['name'] ??= '';
        [$attributes] = $this->formats->normalize([...$data, 'name' => (string) $data['name']]);

        return ApiResponse::success(
            $this->scale->test($attributes, (string) $data['barcode']),
            'Scale barcode tested.',
        );
    }
}
