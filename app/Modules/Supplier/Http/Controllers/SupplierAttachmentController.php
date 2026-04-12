<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Supplier\Models\Supplier;
use App\Services\AttachmentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Supplier $supplier): JsonResponse
    {
        $rows = $this->attachmentService->listFor($supplier);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): string => $this->downloadUrl($supplier, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, Supplier $supplier): JsonResponse
    {
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store($supplier, $file, $userId !== null ? (int) $userId : null);

        return ApiResponse::created(
            AttachmentResponseData::fromModel(
                $attachment,
                $this->downloadUrl($supplier, $attachment)
            )->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Supplier $supplier, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($supplier, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel(
                $attachment,
                $this->downloadUrl($supplier, $attachment)
            )->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function download(Supplier $supplier, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($supplier, $attachment);
        $this->attachmentService->assertStoredFileExists($attachment);

        $disk = Storage::disk('local');
        if (! $disk instanceof FilesystemAdapter) {
            abort(500, 'Local filesystem is not configured for downloads.');
        }

        return response()->download($disk->path($attachment->file_path), $attachment->file_name);
    }

    public function destroy(Supplier $supplier, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($supplier, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    private function ensureMorph(Supplier $supplier, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $supplier->getMorphClass()
            || (int) $attachment->attachable_id !== (int) $supplier->id) {
            abort(404);
        }
    }

    private function downloadUrl(Supplier $supplier, Attachment $attachment): string
    {
        return route('suppliers.attachments.download', [
            'supplier' => $supplier->getKey(),
            'attachment' => $attachment->getKey(),
        ]);
    }
}
