<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Item\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\DeliversAttachmentFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Inventory\Item\Models\Item;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ItemAttachmentController extends Controller
{
    use DeliversAttachmentFiles;

    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Item $item): JsonResponse
    {
        $rows = $this->attachmentService->listFor($item);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): array => $this->urls($item, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, Item $item): JsonResponse
    {
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store($item, $file, $userId !== null ? (int) $userId : null);
        $urls = $this->urls($item, $attachment);

        return ApiResponse::created(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Item $item, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($item, $attachment);
        $urls = $this->urls($item, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function view(Item $item, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($item, $attachment);

        return $this->deliverAttachmentView($attachment);
    }

    public function download(Item $item, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($item, $attachment);

        return $this->deliverAttachmentDownload($attachment);
    }

    public function setPrimary(Item $item, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($item, $attachment);
        $updated = $this->attachmentService->setPrimaryImage($item, $attachment);
        $urls = $this->urls($item, $updated);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($updated, $urls['download'], $urls['view'])->toArray(),
            'Primary image updated successfully.'
        );
    }

    public function destroy(Item $item, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($item, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    protected function resolveAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    private function ensureMorph(Item $item, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $item->getMorphClass()
            || (int) $attachment->attachable_id !== (int) $item->id) {
            abort(404, 'Attachment not found for this item.', ['X-Error-Code' => 'ITEM_ATTACHMENT_SCOPE_MISMATCH']);
        }
    }

    /**
     * @return array{download: string, view: string}
     */
    private function urls(Item $item, Attachment $attachment): array
    {
        return [
            'download' => route('items.attachments.download', [
                'item' => $item->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
            'view' => route('items.attachments.view', [
                'item' => $item->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
        ];
    }
}
