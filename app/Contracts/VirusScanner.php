<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\VirusScanning\VirusScanResult;

/**
 * Contract for malware scanners so we can swap implementations.
 *
 * Used by: AttachmentService (injected), AppServiceProvider (binds Null or ClamAV).
 * Implementations: NullVirusScanner (dev), ClamAvVirusScanner (production).
 */
interface VirusScanner
{
    /**
     * Scan a file already stored on the given disk.
     */
    public function scan(string $disk, string $path): VirusScanResult;
}
