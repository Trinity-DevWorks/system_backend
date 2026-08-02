<?php

declare(strict_types=1);

namespace App\Services\VirusScanning;

use App\Contracts\VirusScanner;
use App\Enums\AttachmentScanStatus;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Real malware scanner: streams the file to a ClamAV daemon over TCP.
 *
 * Used when ATTACHMENT_VIRUS_SCAN_DRIVER=clamav. Bound in AppServiceProvider.
 * Requires clamd at CLAMAV_HOST:CLAMAV_PORT. Called from AttachmentService::finalizeProcessing().
 */
final class ClamAvVirusScanner implements VirusScanner
{
    public function scan(string $disk, string $path): VirusScanResult
    {
        $host = (string) config('attachments.virus_scan.clamav.host', '127.0.0.1');
        $port = (int) config('attachments.virus_scan.clamav.port', 3310);
        $timeout = (int) config('attachments.virus_scan.clamav.timeout', 30);

        try {
            $stream = Storage::disk($disk)->readStream($path);
            if ($stream === false || $stream === null) {
                throw new RuntimeException('Unable to open attachment stream for virus scan.');
            }

            try {
                return $this->scanStream($stream, $host, $port, $timeout);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } catch (Throwable $e) {
            if (config('attachments.virus_scan.fail_closed', true)) {
                return new VirusScanResult(
                    status: AttachmentScanStatus::Failed,
                    message: $e->getMessage(),
                );
            }

            return new VirusScanResult(
                status: AttachmentScanStatus::Skipped,
                message: 'ClamAV unavailable; fail-open: '.$e->getMessage(),
            );
        }
    }

    /**
     * @param  resource  $stream
     */
    private function scanStream($stream, string $host, int $port, int $timeout): VirusScanResult
    {
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($socket === false) {
            throw new RuntimeException("ClamAV connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);

        fwrite($socket, "zINSTREAM\0");

        while (! feof($stream)) {
            $chunk = fread($stream, 2048);
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($socket, pack('N', strlen($chunk)).$chunk);
        }

        fwrite($socket, pack('N', 0));

        $response = trim((string) stream_get_contents($socket));
        fclose($socket);

        if ($response === '') {
            throw new RuntimeException('Empty response from ClamAV.');
        }

        if (str_ends_with($response, 'OK') || str_contains($response, ': OK')) {
            return new VirusScanResult(status: AttachmentScanStatus::Clean);
        }

        if (str_contains($response, 'FOUND')) {
            $signature = trim(str_replace(['stream:', 'FOUND'], '', $response));

            return new VirusScanResult(
                status: AttachmentScanStatus::Infected,
                signature: $signature !== '' ? $signature : null,
                message: $response,
            );
        }

        throw new RuntimeException('Unexpected ClamAV response: '.$response);
    }
}
