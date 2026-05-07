<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Customer\Models\Customer;
use App\Services\AttachmentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerAttachmentController extends Controller
{
    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Customer $customer): JsonResponse
    {
        $rows = $this->attachmentService->listFor($customer);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): string => $this->downloadUrl($customer, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, Customer $customer): JsonResponse
    {
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store($customer, $file, $userId !== null ? (int) $userId : null);

        return ApiResponse::created(
            AttachmentResponseData::fromModel(
                $attachment,
                $this->downloadUrl($customer, $attachment)
            )->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Customer $customer, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($customer, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel(
                $attachment,
                $this->downloadUrl($customer, $attachment)
            )->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function download(Customer $customer, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($customer, $attachment);
        $this->attachmentService->assertStoredFileExists($attachment);

        $disk = Storage::disk('local');
        if (! $disk instanceof FilesystemAdapter) {
            abort(500, 'Local filesystem is not configured for downloads.', ['X-Error-Code' => 'ATTACHMENT_DOWNLOAD_STORAGE_NOT_CONFIGURED']);
        }

        return response()->download($disk->path($attachment->file_path), $attachment->file_name);
    }

    public function destroy(Customer $customer, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($customer, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    private function ensureMorph(Customer $customer, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $customer->getMorphClass()
            || (int) $attachment->attachable_id !== (int) $customer->id) {
            abort(404, 'Attachment not found for this customer.', ['X-Error-Code' => 'CUSTOMER_ATTACHMENT_SCOPE_MISMATCH']);
        }
    }

    private function downloadUrl(Customer $customer, Attachment $attachment): string
    {
        return route('customers.attachments.download', [
            'customer' => $customer->getKey(),
            'attachment' => $attachment->getKey(),
        ]);
    }
}
