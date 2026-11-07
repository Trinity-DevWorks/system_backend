<?php

declare(strict_types=1);

namespace App\Modules\DocumentNumbering\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Modules\DocumentNumbering\Http\Requests\UpdateDocumentNumberSettingsRequest;
use App\Modules\DocumentNumbering\Services\DocumentNumberSettingService;
use Illuminate\Http\JsonResponse;

class DocumentNumberSettingController extends Controller
{
    public function __construct(private readonly DocumentNumberSettingService $service) {}

    public function index(): JsonResponse
    {
        return ApiResponse::success($this->service->list(), 'Document numbering fetched successfully.');
    }

    public function update(UpdateDocumentNumberSettingsRequest $request): JsonResponse
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $request->validated('settings');
        $this->service->update($rows);

        return ApiResponse::success($this->service->list(), 'Document numbering updated successfully.');
    }
}
