<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\DeliversAttachmentFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Supplier\Models\Supplier;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SupplierAttachmentController extends Controller
{
    use DeliversAttachmentFiles;

    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Supplier $supplier): JsonResponse
    {
        $rows = $this->attachmentService->listFor($supplier);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): array => $this->urls($supplier, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, Supplier $supplier): JsonResponse
    {
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store($supplier, $file, $userId !== null ? (string) $userId : null);
        $urls = $this->urls($supplier, $attachment);

        return ApiResponse::created(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Supplier $supplier, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($supplier, $attachment);
        $urls = $this->urls($supplier, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function view(Supplier $supplier, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($supplier, $attachment);

        return $this->deliverAttachmentView($attachment);
    }

    public function download(Supplier $supplier, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($supplier, $attachment);

        return $this->deliverAttachmentDownload($attachment);
    }

    public function destroy(Supplier $supplier, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($supplier, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    protected function resolveAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    private function ensureMorph(Supplier $supplier, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $supplier->getMorphClass()
            || (string) $attachment->attachable_id !== (string) $supplier->id) {
            abort(404, 'Attachment not found for this supplier.', ['X-Error-Code' => 'SUPPLIER_ATTACHMENT_SCOPE_MISMATCH']);
        }
    }

    /**
     * @return array{download: string, view: string}
     */
    private function urls(Supplier $supplier, Attachment $attachment): array
    {
        return [
            'download' => route('suppliers.attachments.download', [
                'supplier' => $supplier->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
            'view' => route('suppliers.attachments.view', [
                'supplier' => $supplier->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
        ];
    }
}
