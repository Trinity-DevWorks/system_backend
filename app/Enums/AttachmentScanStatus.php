<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Result of a virus / malware scan on an uploaded file.
 *
 * Used by: Attachment model (scan_status column), VirusScanResult, scanners,
 *          AttachmentService::finalizeProcessing(), and API DTO responses.
 */
enum AttachmentScanStatus: string
{
    case Pending = 'pending';
    case Clean = 'clean';
    case Infected = 'infected';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
