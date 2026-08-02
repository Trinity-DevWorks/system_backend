<?php

declare(strict_types=1);

namespace App\Services\VirusScanning;

use App\Enums\AttachmentScanStatus;

/**
 * Simple result object returned by a virus scanner (clean / infected / failed / skipped).
 *
 * Used by: VirusScanner implementations → AttachmentService::finalizeProcessing().
 */
final readonly class VirusScanResult
{
    public function __construct(
        public AttachmentScanStatus $status,
        public ?string $signature = null,
        public ?string $message = null,
    ) {}

    public function isClean(): bool
    {
        return $this->status === AttachmentScanStatus::Clean
            || $this->status === AttachmentScanStatus::Skipped;
    }

    public function isInfected(): bool
    {
        return $this->status === AttachmentScanStatus::Infected;
    }
}
