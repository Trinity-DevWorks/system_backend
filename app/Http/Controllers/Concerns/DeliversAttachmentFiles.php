<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

trait DeliversAttachmentFiles
{
    protected function deliverAttachmentDownload(Attachment $attachment): BinaryFileResponse
    {
        return $this->resolveAttachmentService()->downloadResponse($attachment);
    }

    protected function deliverAttachmentView(Attachment $attachment): BinaryFileResponse
    {
        return $this->resolveAttachmentService()->inlineResponse($attachment);
    }

    abstract protected function resolveAttachmentService(): AttachmentService;
}
