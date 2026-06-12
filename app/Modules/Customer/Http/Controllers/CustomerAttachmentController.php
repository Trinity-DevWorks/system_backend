<?php

declare(strict_types=1);

namespace App\Modules\Customer\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\DeliversAttachmentFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\Customer\Models\Customer;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class CustomerAttachmentController extends Controller
{
    use DeliversAttachmentFiles;

    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {}

    public function index(Customer $customer): JsonResponse
    {
        $rows = $this->attachmentService->listFor($customer);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): array => $this->urls($customer, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, Customer $customer): JsonResponse
    {
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store($customer, $file, $userId !== null ? (string) $userId : null);
        $urls = $this->urls($customer, $attachment);

        return ApiResponse::created(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Customer $customer, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($customer, $attachment);
        $urls = $this->urls($customer, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function view(Customer $customer, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($customer, $attachment);

        return $this->deliverAttachmentView($attachment);
    }

    public function download(Customer $customer, Attachment $attachment): BinaryFileResponse
    {
        $this->ensureMorph($customer, $attachment);

        return $this->deliverAttachmentDownload($attachment);
    }

    public function destroy(Customer $customer, Attachment $attachment): JsonResponse
    {
        $this->ensureMorph($customer, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    protected function resolveAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    private function ensureMorph(Customer $customer, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $customer->getMorphClass()
            || (string) $attachment->attachable_id !== (string) $customer->id) {
            abort(404, 'Attachment not found for this customer.', ['X-Error-Code' => 'CUSTOMER_ATTACHMENT_SCOPE_MISMATCH']);
        }
    }

    /**
     * @return array{download: string, view: string}
     */
    private function urls(Customer $customer, Attachment $attachment): array
    {
        return [
            'download' => route('customers.attachments.download', [
                'customer' => $customer->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
            'view' => route('customers.attachments.view', [
                'customer' => $customer->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
        ];
    }
}
