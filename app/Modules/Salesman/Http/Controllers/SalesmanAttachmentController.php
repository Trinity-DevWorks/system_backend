<?php

declare(strict_types=1);

namespace App\Modules\Salesman\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\DeliversAttachmentFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Salesman\Models\Salesman;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SalesmanAttachmentController extends Controller
{
    use DeliversAttachmentFiles;

    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Salesman $salesman): JsonResponse
    {
        $rows = $this->attachmentService->listFor($salesman);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): array => $this->urls($salesman, $a)
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
        $urls = $this->urls($salesman, $attachment);

        return ApiResponse::created(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Salesman $salesman, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($salesman, $attachment);
        $urls = $this->urls($salesman, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function view(Salesman $salesman, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($salesman, $attachment);

        return $this->deliverAttachmentView($attachment);
    }

    public function download(Salesman $salesman, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($salesman, $attachment);

        return $this->deliverAttachmentDownload($attachment);
    }

    public function destroy(Salesman $salesman, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($salesman, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    protected function resolveAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    private function ensureMorph(Salesman $salesman, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $salesman->getMorphClass()
            || (int) $attachment->attachable_id !== (int) $salesman->id) {
            abort(404, 'Attachment not found for this salesman.', ['X-Error-Code' => 'SALESMAN_ATTACHMENT_SCOPE_MISMATCH']);
        }
    }

    /**
     * @return array{download: string, view: string}
     */
    private function urls(Salesman $salesman, Attachment $attachment): array
    {
        return [
            'download' => route('salesmen.attachments.download', [
                'salesman' => $salesman->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
            'view' => route('salesmen.attachments.view', [
                'salesman' => $salesman->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
        ];
    }
}
