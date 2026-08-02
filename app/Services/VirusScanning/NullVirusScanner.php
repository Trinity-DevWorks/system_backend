<?php

declare(strict_types=1);

namespace App\Services\VirusScanning;

use App\Contracts\VirusScanner;
use App\Enums\AttachmentScanStatus;

/**
 * No-op scanner for local/dev — marks the file as "skipped" without reading bytes.
 *
 * Used when ATTACHMENT_VIRUS_SCAN_DRIVER=null (default). Bound in AppServiceProvider.
 * Swap to ClamAvVirusScanner in production.
 */
final class NullVirusScanner implements VirusScanner
{
    public function scan(string $disk, string $path): VirusScanResult
    {
        return new VirusScanResult(
            status: AttachmentScanStatus::Skipped,
            message: 'Virus scanning disabled (null driver).',
        );
    }
}
