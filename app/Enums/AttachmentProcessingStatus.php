<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * High-level lifecycle of an attachment after upload.
 *
 * Used by: Attachment model (processing_status), AttachmentService (store/finalize),
 *          API responses, and the frontend Ready / Processing / Rejected badges.
 */
enum AttachmentProcessingStatus: string
{
    /** Upload received; waiting for async scan/finalize. */
    case Pending = 'pending';

    /** Safe to download / preview. */
    case Ready = 'ready';

    /** Rejected (e.g. malware); file removed or blocked. */
    case Rejected = 'rejected';
}
