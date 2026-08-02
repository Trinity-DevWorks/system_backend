<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Attachment;
use App\Models\Tenant;
use App\Services\AttachmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Queue job that finishes a large upload in the background (checksum check + virus scan).
 *
 * Dispatched by: AttachmentService::store() when the file is over the async size threshold.
 * Run by: `php artisan queue:work`. Marks the attachment ready or rejected.
 */
class ProcessAttachmentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $attachmentId,
        public readonly ?string $tenantId = null,
    ) {}

    public function handle(AttachmentService $attachments): void
    {
        $tenantId = $this->tenantId ?? tenant('id');

        if ($tenantId) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                return;
            }

            $tenant->run(function () use ($attachments): void {
                $attachment = Attachment::query()->find($this->attachmentId);
                if ($attachment !== null) {
                    $attachments->finalizeProcessing($attachment);
                }
            });

            return;
        }

        $attachment = Attachment::query()->find($this->attachmentId);
        if ($attachment !== null) {
            $attachments->finalizeProcessing($attachment);
        }
    }
}
