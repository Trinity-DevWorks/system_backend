<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Salesman\Models\Salesman;
use App\Services\AttachmentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesmanAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Salesman $salesman): JsonResponse
    {
        $rows = $this->attachmentService->listFor($salesman);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): string => $this->downloadUrl($salesman, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, Salesman $salesman): JsonResponse
    {
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store($salesman, $file, $userId !== null ? (int) $userId : null);

        return ApiResponse::created(
            AttachmentResponseData::fromModel(
                $attachment,
                $this->downloadUrl($salesman, $attachment)
            )->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Salesman $salesman, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($salesman, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel(
                $attachment,
                $this->downloadUrl($salesman, $attachment)
            )->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function download(Salesman $salesman, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($salesman, $attachment);
        $this->attachmentService->assertStoredFileExists($attachment);

        $disk = Storage::disk('local');
        if (! $disk instanceof FilesystemAdapter) {
            abort(500, 'Local filesystem is not configured for downloads.', ['X-Error-Code' => 'ATTACHMENT_DOWNLOAD_STORAGE_NOT_CONFIGURED']);
        }

        return response()->download($disk->path($attachment->file_path), $attachment->file_name);
    }

    public function destroy(Salesman $salesman, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($salesman, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    private function ensureMorph(Salesman $salesman, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $salesman->getMorphClass()
            || (int) $attachment->attachable_id !== (int) $salesman->id) {
            abort(404, 'Attachment not found for this salesman.', ['X-Error-Code' => 'SALESMAN_ATTACHMENT_SCOPE_MISMATCH']);
        }
    }

    private function downloadUrl(Salesman $salesman, Attachment $attachment): string
    {
        return route('salesmen.attachments.download', [
            'salesman' => $salesman->getKey(),
            'attachment' => $attachment->getKey(),
        ]);
    }
}
