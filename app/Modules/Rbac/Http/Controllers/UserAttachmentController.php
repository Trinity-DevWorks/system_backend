<?php

declare(strict_types=1);

namespace App\Modules\Rbac\Http\Controllers;

use App\DTOs\AttachmentResponseData;
use App\Http\Controllers\Concerns\DeliversAttachmentFiles;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Attachment;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserAttachmentController extends Controller
{
    use DeliversAttachmentFiles;

    public function __construct(
        private readonly AttachmentService $attachmentService,
        private readonly PermissionService $permissionService,
    ) {}

    public function index(User $user): JsonResponse
    {
        $this->assertCanView($user);
        $rows = $this->attachmentService->listFor($user);

        return ApiResponse::success(
            AttachmentResponseData::collectionToArray(
                $rows,
                fn (Attachment $a): array => $this->urls($user, $a)
            ),
            'Attachments fetched successfully.'
        );
    }

    public function store(StoreAttachmentRequest $request, User $user): JsonResponse
    {
        $this->assertCanEdit($user);
        $file = $request->file('file');
        assert($file !== null);
        $actorId = $request->user()?->id;
        $attachment = $this->attachmentService->store(
            $user,
            $file,
            $actorId !== null ? (string) $actorId : null
        );
        $urls = $this->urls($user, $attachment);

        return ApiResponse::created(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment uploaded successfully.'
        );
    }

    public function show(User $user, Attachment $attachment): JsonResponse
    {
        $this->assertCanView($user);
        $this->ensureMorph($user, $attachment);
        $urls = $this->urls($user, $attachment);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($attachment, $urls['download'], $urls['view'])->toArray(),
            'Attachment fetched successfully.'
        );
    }

    public function view(User $user, Attachment $attachment): StreamedResponse
    {
        $this->assertCanView($user);
        $this->ensureMorph($user, $attachment);

        return $this->deliverAttachmentView($attachment);
    }

    public function download(User $user, Attachment $attachment): StreamedResponse
    {
        $this->assertCanView($user);
        $this->ensureMorph($user, $attachment);

        return $this->deliverAttachmentDownload($attachment);
    }

    public function setPrimary(User $user, Attachment $attachment): JsonResponse
    {
        $this->assertCanEdit($user);
        $this->ensureMorph($user, $attachment);
        $updated = $this->attachmentService->setPrimaryImage($user, $attachment);
        $urls = $this->urls($user, $updated);

        return ApiResponse::success(
            AttachmentResponseData::fromModel($updated, $urls['download'], $urls['view'])->toArray(),
            'Avatar updated successfully.'
        );
    }

    public function destroy(User $user, Attachment $attachment): JsonResponse
    {
        $this->assertCanEdit($user);
        $this->ensureMorph($user, $attachment);
        $this->attachmentService->delete($attachment);

        return ApiResponse::success(null, 'Attachment deleted successfully.');
    }

    protected function resolveAttachmentService(): AttachmentService
    {
        return $this->attachmentService;
    }

    private function assertCanView(User $target): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        if ((string) $actor->id === (string) $target->id) {
            return;
        }

        if ($this->permissionService->userHas('users', 'view', $actor)
            || $this->permissionService->userHas('users', 'edit', $actor)) {
            return;
        }

        abort(403, 'You are not allowed to view this user attachment.', [
            'X-Error-Code' => 'USER_ATTACHMENT_FORBIDDEN',
        ]);
    }

    private function assertCanEdit(User $target): void
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            abort(401);
        }

        if ((string) $actor->id === (string) $target->id) {
            return;
        }

        if ($this->permissionService->userHas('users', 'edit', $actor)) {
            return;
        }

        abort(403, 'You are not allowed to manage this user attachment.', [
            'X-Error-Code' => 'USER_ATTACHMENT_FORBIDDEN',
        ]);
    }

    private function ensureMorph(User $user, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $user->getMorphClass()
            || (string) $attachment->attachable_id !== (string) $user->id) {
            abort(404, 'Attachment not found for this user.', [
                'X-Error-Code' => 'USER_ATTACHMENT_SCOPE_MISMATCH',
            ]);
        }
    }

    /**
     * @return array{download: string, view: string}
     */
    private function urls(User $user, Attachment $attachment): array
    {
        return [
            'download' => route('users.attachments.download', [
                'user' => $user->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
            'view' => route('users.attachments.view', [
                'user' => $user->getKey(),
                'attachment' => $attachment->getKey(),
            ]),
        ];
    }
}
