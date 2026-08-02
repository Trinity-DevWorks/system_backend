<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait DeliversAttachmentFiles
{
    protected function deliverAttachmentDownload(Attachment $attachment): StreamedResponse
    {
        return $this->resolveAttachmentService()->downloadResponse($attachment);
    }

    protected function deliverAttachmentView(Attachment $attachment): StreamedResponse
    {
        return $this->resolveAttachmentService()->inlineResponse($attachment);
    }

    abstract protected function resolveAttachmentService(): AttachmentService;
}
