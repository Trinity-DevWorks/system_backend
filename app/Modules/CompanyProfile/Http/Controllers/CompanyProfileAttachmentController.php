<?php

declare(strict_types=1);

namespace App\Modules\CompanyProfile\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\DeliversAttachmentFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\CompanyProfile\Services\CompanyProfileService;
use App\Services\AttachmentService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyProfileAttachmentController extends Controller
{
    use DeliversAttachmentFiles;

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly CompanyProfileService $companyProfileService,
    ) {}

    public function index(): JsonResponse
    {
        $profile = CompanyProfile::singleton();
        $rows = $this->attachmentService->listFor($profile);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): array => $this->urls($a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request): JsonResponse
    {
        $profile = CompanyProfile::singleton();
        $file = $request->file('file');
        assert($file !== null);
        $userId = $request->user()?->id;
        $attachment = $this->attachmentService->store(
            $profile,
            $file,
            $userId !== null ? (string) $userId : null
        );
        $this->companyProfileService->forgetCache();
        $urls = $this->urls($attachment);

        return ApiResponse::created(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(Attachment $attachment): JsonResponse
    {
        $profile = CompanyProfile::singleton();
        $this->ensureMorph($profile, $attachment);
        $urls = $this->urls($attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function view(Attachment $attachment): StreamedResponse
    {
        $profile = CompanyProfile::singleton();
        $this->ensureMorph($profile, $attachment);

        return $this->deliverAttachmentView($attachment);
    }

    public function download(Attachment $attachment): StreamedResponse
    {
        $profile = CompanyProfile::singleton();
        $this->ensureMorph($profile, $attachment);

        return $this->deliverAttachmentDownload($attachment);
    }

    public function setPrimary(Attachment $attachment): JsonResponse
    {
        $profile = CompanyProfile::singleton();
        $this->ensureMorph($profile, $attachment);
        $updated = $this->attachmentService->setPrimaryImage($profile, $attachment);
        $this->companyProfileService->forgetCache();
        $urls = $this->urls($updated);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($updated, $urls['download'], $urls['view'])->toArray(),
            'Company logo updated successfully.'
        );
    }

    public function destroy(Attachment $attachment): JsonResponse
    {
        $profile = CompanyProfile::singleton();
        $this->ensureMorph($profile, $attachment);
        $this->attachmentService->delete($attachment);
        $this->companyProfileService->forgetCache();

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    protected function resolveAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    private function ensureMorph(CompanyProfile $profile, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $profile->getMorphClass()
            || (string) $attachment->attachable_id !== (string) $profile->id) {
            abort(404, 'Attachment not found for this company profile.', [
                'X-Error-Code' => 'COMPANY_PROFILE_ATTACHMENT_SCOPE_MISMATCH',
            ]);
        }
    }

    /**
     * @return array{download: string, view: string}
     */
    private function urls(Attachment $attachment): array
    {
        return [
            'download' => route('company-profile.attachments.download', [
                'attachment' => $attachment->getKey(),
            ]),
            'view' => route('company-profile.attachments.view', [
                'attachment' => $attachment->getKey(),
            ]),
        ];
    }
}
