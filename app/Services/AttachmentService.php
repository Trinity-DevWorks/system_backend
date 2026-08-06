<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\VirusScanner;
use App\Enums\AttachmentProcessingStatus;
use App\Enums\AttachmentScanStatus;
use App\Enums\AttachmentViewerCategory;
use App\Jobs\ProcessAttachmentJob;
use App\Models\Attachment;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService
{
    public function __construct(
        private readonly VirusScanner $virusScanner,
        private readonly AuditWriter $auditWriter,
    ) {}

    /**
     * @return Collection<int, Attachment>
     */
    public function listFor(Customer|Supplier|Salesman|Item|CompanyProfile $attachable): Collection
    {
        return $attachable->attachments()
            ->where('processing_status', '!=', AttachmentProcessingStatus::Rejected)
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();
    }

    public function store(Customer|Supplier|Salesman|Item|CompanyProfile $attachable, UploadedFile $file, ?string $uploadedByUserId): Attachment
    {
        $this->assertWithinQuota($attachable);

        $disk = $this->attachmentDisk();
        $algo = (string) config('attachments.checksum_algo', 'sha256');
        $checksum = hash_file($algo, $file->getRealPath() ?: $file->getPathname());
        if ($checksum === false) {
            abort(500, 'Failed to compute file checksum.', ['X-Error-Code' => 'ATTACHMENT_CHECKSUM_FAILED']);
        }

        $original = $file->getClientOriginalName() ?: 'upload';
        $safeBase = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
        $storedName = $safeBase.'_'.Str::uuid()->toString().'.'.$ext;
        $dir = 'attachments/'.$attachable->getMorphClass().'/'.$attachable->getKey();

        $classified = AttachmentClassifier::fromUploadedFile($file);
        $isPrimary = $this->shouldMarkAsPrimaryOnStore($attachable, $classified['viewer_category']);
        $async = $this->shouldProcessAsync((int) $file->getSize());

        $path = $file->storeAs($dir, $storedName, $disk);
        if ($path === false) {
            abort(500, 'Failed to store uploaded file.', ['X-Error-Code' => 'ATTACHMENT_STORE_FAILED']);
        }

        try {
            $attachment = DB::transaction(function () use (
                $attachable,
                $disk,
                $path,
                $original,
                $file,
                $classified,
                $isPrimary,
                $uploadedByUserId,
                $checksum,
                $algo,
                $async,
            ): Attachment {
                return $attachable->attachments()->create([
                    'disk' => $disk,
                    'file_path' => $path,
                    'file_name' => $original,
                    'mime_type' => $classified['mime_type'],
                    'file_size' => (int) $file->getSize(),
                    'checksum' => $checksum,
                    'checksum_algo' => $algo,
                    'viewer_category' => $classified['viewer_category'],
                    'can_preview' => $classified['can_preview'],
                    'is_primary' => $isPrimary,
                    'processing_status' => $async
                        ? AttachmentProcessingStatus::Pending
                        : AttachmentProcessingStatus::Ready,
                    'scan_status' => AttachmentScanStatus::Pending,
                    'uploaded_by' => $uploadedByUserId,
                ]);
            });
        } catch (\Throwable $e) {
            $this->deleteStoredFile($disk, $path);

            throw $e;
        }

        if ($async) {
            ProcessAttachmentJob::dispatch($attachment->id, tenant('id') !== null ? (string) tenant('id') : null);
        } else {
            $this->finalizeProcessing($attachment);
            $attachment = $attachment->fresh() ?? $attachment;
        }

        return $attachment;
    }

    /**
     * Verify integrity + virus scan; mark ready or reject.
     */
    public function finalizeProcessing(Attachment $attachment): Attachment
    {
        if ($attachment->processing_status === AttachmentProcessingStatus::Rejected) {
            return $attachment;
        }

        if (! Storage::disk($attachment->disk)->exists($attachment->file_path)) {
            $attachment->update([
                'processing_status' => AttachmentProcessingStatus::Rejected,
                'scan_status' => AttachmentScanStatus::Failed,
                'scan_signature' => null,
                'scanned_at' => now(),
            ]);

            return $attachment->fresh() ?? $attachment;
        }

        if (! $this->checksumMatches($attachment)) {
            $this->rejectAndRemoveFile($attachment, AttachmentScanStatus::Failed, 'Checksum mismatch.');

            return $attachment->fresh() ?? $attachment;
        }

        $result = $this->virusScanner->scan($attachment->disk, $attachment->file_path);

        if ($result->isInfected() || $result->status === AttachmentScanStatus::Failed) {
            $this->rejectAndRemoveFile(
                $attachment,
                $result->status,
                $result->message,
                $result->signature,
            );

            return $attachment->fresh() ?? $attachment;
        }

        $attachment->update([
            'processing_status' => AttachmentProcessingStatus::Ready,
            'scan_status' => $result->status,
            'scan_signature' => $result->signature,
            'scanned_at' => now(),
        ]);

        return $attachment->fresh() ?? $attachment;
    }

    public function setPrimaryImage(Item|CompanyProfile $attachable, Attachment $attachment): Attachment
    {
        if ($attachment->attachable_type !== $attachable->getMorphClass()
            || (string) $attachment->attachable_id !== (string) $attachable->getKey()) {
            abort(404, 'Attachment not found for this resource.', ['X-Error-Code' => 'ATTACHMENT_SCOPE_MISMATCH']);
        }

        if ($attachment->viewer_category !== AttachmentViewerCategory::Image) {
            abort(422, 'Only image attachments can be set as primary.', ['X-Error-Code' => 'ATTACHMENT_PRIMARY_IMAGE_ONLY']);
        }

        if (! $attachment->isDownloadable()) {
            abort(422, 'Attachment is not ready to be set as primary.', ['X-Error-Code' => 'ATTACHMENT_NOT_READY']);
        }

        return DB::transaction(function () use ($attachable, $attachment): Attachment {
            $attachable->attachments()
                ->where('viewer_category', AttachmentViewerCategory::Image)
                ->whereKeyNot($attachment->id)
                ->update(['is_primary' => false]);

            $attachment->update(['is_primary' => true]);

            return $attachment->fresh() ?? $attachment;
        });
    }

    /**
     * Soft-delete (audit trail). Blob kept unless purge_files_on_soft_delete.
     */
    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $attachable = $attachment->attachable;
            $wasPrimaryImage = $attachment->is_primary
                && $attachment->viewer_category === AttachmentViewerCategory::Image;

            $disk = $attachment->disk;
            $path = $attachment->file_path;

            $attachment->update(['is_primary' => false]);
            $attachment->delete();

            if (config('attachments.purge_files_on_soft_delete', false)) {
                $this->deleteStoredFile($disk, $path);
            }

            if ($wasPrimaryImage && $this->supportsPrimaryImage($attachable)) {
                $this->promoteNextPrimaryImage($attachable);
            }
        });
    }

    /**
     * Permanent delete: remove DB row + blob.
     */
    public function forceDelete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $attachable = $attachment->attachable;
            $wasPrimaryImage = $attachment->is_primary
                && $attachment->viewer_category === AttachmentViewerCategory::Image;

            $disk = $attachment->disk;
            $path = $attachment->file_path;

            $attachment->forceDelete();
            $this->deleteStoredFile($disk, $path);

            if ($wasPrimaryImage && $this->supportsPrimaryImage($attachable)) {
                $this->promoteNextPrimaryImage($attachable);
            }
        });
    }

    public function assertStoredFileExists(Attachment $attachment): void
    {
        if (! Storage::disk($attachment->disk)->exists($attachment->file_path)) {
            abort(404, 'File not found on storage.', ['X-Error-Code' => 'ATTACHMENT_FILE_MISSING']);
        }
    }

    public function assertDownloadable(Attachment $attachment): void
    {
        if (! $attachment->isDownloadable()) {
            abort(409, 'Attachment is not ready for download.', ['X-Error-Code' => 'ATTACHMENT_NOT_READY']);
        }

        $this->assertStoredFileExists($attachment);
    }

    public function downloadResponse(Attachment $attachment): StreamedResponse
    {
        $this->assertDownloadable($attachment);

        $this->auditWriter->write(
            event: 'download',
            auditable: $attachment,
            newValues: [
                'file_name' => $attachment->file_name,
                'attachable_type' => $attachment->attachable_type,
                'attachable_id' => $attachment->attachable_id,
            ],
            tags: 'attachment,download',
        );

        return Storage::disk($attachment->disk)->download(
            $attachment->file_path,
            $attachment->file_name,
        );
    }

    public function inlineResponse(Attachment $attachment): StreamedResponse
    {
        $this->assertDownloadable($attachment);

        $safeName = str_replace(['"', "\r", "\n"], '', $attachment->file_name);

        return Storage::disk($attachment->disk)->response(
            $attachment->file_path,
            $safeName,
            [
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.$safeName.'"',
            ],
        );
    }

    private function assertWithinQuota(Customer|Supplier|Salesman|Item|CompanyProfile $attachable): void
    {
        $max = (int) config('attachments.max_per_record', 50);
        if ($max <= 0) {
            return;
        }

        $count = $attachable->attachments()->count();
        if ($count >= $max) {
            abort(422, "This record may have at most {$max} attachments.", [
                'X-Error-Code' => 'ATTACHMENT_QUOTA_EXCEEDED',
            ]);
        }
    }

    private function shouldProcessAsync(int $fileSizeBytes): bool
    {
        if (! config('attachments.async.enabled', true)) {
            return false;
        }

        if (config('attachments.async.force', false)) {
            return true;
        }

        $thresholdKb = (int) config('attachments.async.threshold_kilobytes', 1024);

        return $fileSizeBytes >= ($thresholdKb * 1024);
    }

    private function checksumMatches(Attachment $attachment): bool
    {
        if ($attachment->checksum === null || $attachment->checksum_algo === null) {
            return true;
        }

        $absolute = Storage::disk($attachment->disk)->path($attachment->file_path);

        // S3 (and non-local adapters) may not support path(); hash via stream instead.
        try {
            $hash = hash_file($attachment->checksum_algo, $absolute);
            if ($hash !== false) {
                return hash_equals($attachment->checksum, $hash);
            }
        } catch (\Throwable) {
            // Fall through to stream hashing.
        }

        $stream = Storage::disk($attachment->disk)->readStream($attachment->file_path);
        if ($stream === false || $stream === null) {
            return false;
        }

        $ctx = hash_init($attachment->checksum_algo);

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return hash_equals($attachment->checksum, hash_final($ctx));
    }

    private function rejectAndRemoveFile(
        Attachment $attachment,
        AttachmentScanStatus $scanStatus,
        ?string $message = null,
        ?string $signature = null,
    ): void {
        $disk = $attachment->disk;
        $path = $attachment->file_path;

        $attachment->update([
            'processing_status' => AttachmentProcessingStatus::Rejected,
            'scan_status' => $scanStatus,
            'scan_signature' => $signature ?? $message,
            'scanned_at' => now(),
            'is_primary' => false,
        ]);

        $this->deleteStoredFile($disk, $path);
    }

    private function shouldMarkAsPrimaryOnStore(
        Customer|Supplier|Salesman|Item|CompanyProfile $attachable,
        AttachmentViewerCategory $category,
    ): bool {
        if (! $this->supportsPrimaryImage($attachable) || $category !== AttachmentViewerCategory::Image) {
            return false;
        }

        return ! $attachable->attachments()
            ->where('viewer_category', AttachmentViewerCategory::Image)
            ->where('is_primary', true)
            ->exists();
    }

    private function supportsPrimaryImage(mixed $attachable): bool
    {
        return $attachable instanceof Item || $attachable instanceof CompanyProfile;
    }

    private function promoteNextPrimaryImage(Item|CompanyProfile $attachable): void
    {
        $next = $attachable->attachments()
            ->where('viewer_category', AttachmentViewerCategory::Image)
            ->where('processing_status', AttachmentProcessingStatus::Ready)
            ->orderByDesc('created_at')
            ->first();

        if ($next !== null) {
            $next->update(['is_primary' => true]);
        }
    }

    private function attachmentDisk(): string
    {
        return (string) config('attachments.disk', 'local');
    }

    private function deleteStoredFile(string $disk, string $path): void
    {
        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
