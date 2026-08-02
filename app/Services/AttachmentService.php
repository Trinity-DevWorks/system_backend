<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentViewerCategory;
use App\Models\Attachment;
use App\Modules\CompanyProfile\Models\CompanyProfile;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Item\Models\Item;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttachmentService
{
    /**
     * @return Collection<int, Attachment>
     */
    public function listFor(Customer|Supplier|Salesman|Item|CompanyProfile $attachable): Collection
    {
        return $attachable->attachments()
            ->orderByDesc('is_primary')
            ->orderByDesc('created_at')
            ->get();
    }

    public function store(Customer|Supplier|Salesman|Item|CompanyProfile $attachable, UploadedFile $file, ?string $uploadedByUserId): Attachment
    {
        return DB::transaction(function () use ($attachable, $file, $uploadedByUserId): Attachment {
            $original = $file->getClientOriginalName() ?: 'upload';
            $safeBase = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
            $storedName = $safeBase.'_'.Str::uuid()->toString().'.'.$ext;

            $dir = 'attachments/'.$attachable->getMorphClass().'/'.$attachable->getKey();
            $path = $file->storeAs($dir, $storedName, 'local');

            $classified = AttachmentClassifier::fromUploadedFile($file);
            $isPrimary = $this->shouldMarkAsPrimaryOnStore($attachable, $classified['viewer_category']);

            return $attachable->attachments()->create([
                'file_path' => $path,
                'file_name' => $original,
                'mime_type' => $classified['mime_type'],
                'file_size' => (int) $file->getSize(),
                'viewer_category' => $classified['viewer_category'],
                'can_preview' => $classified['can_preview'],
                'is_primary' => $isPrimary,
                'uploaded_by' => $uploadedByUserId,
            ]);
        });
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

        return DB::transaction(function () use ($attachable, $attachment): Attachment {
            $attachable->attachments()
                ->where('viewer_category', AttachmentViewerCategory::Image)
                ->whereKeyNot($attachment->id)
                ->update(['is_primary' => false]);

            $attachment->update(['is_primary' => true]);

            return $attachment->fresh() ?? $attachment;
        });
    }

    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            $attachable = $attachment->attachable;
            $wasPrimaryImage = $attachment->is_primary
                && $attachment->viewer_category === AttachmentViewerCategory::Image;

            if (Storage::disk('local')->exists($attachment->file_path)) {
                Storage::disk('local')->delete($attachment->file_path);
            }

            $attachment->delete();

            if ($wasPrimaryImage && $this->supportsPrimaryImage($attachable)) {
                $this->promoteNextPrimaryImage($attachable);
            }
        });
    }

    public function assertStoredFileExists(Attachment $attachment): void
    {
        if (! Storage::disk('local')->exists($attachment->file_path)) {
            abort(404, 'File not found on storage.');
        }
    }

    public function downloadResponse(Attachment $attachment): BinaryFileResponse
    {
        $this->assertStoredFileExists($attachment);
        $disk = $this->localDisk();

        return response()->download($disk->path($attachment->file_path), $attachment->file_name);
    }

    public function inlineResponse(Attachment $attachment): BinaryFileResponse
    {
        $this->assertStoredFileExists($attachment);
        $disk = $this->localDisk();
        $safeName = str_replace(['"', "\r", "\n"], '', $attachment->file_name);

        return response()->file($disk->path($attachment->file_path), [
            'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.$safeName.'"',
        ]);
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
            ->orderByDesc('created_at')
            ->first();

        if ($next !== null) {
            $next->update(['is_primary' => true]);
        }
    }

    private function localDisk(): FilesystemAdapter
    {
        $disk = Storage::disk('local');
        if (! $disk instanceof FilesystemAdapter) {
            abort(500, 'Local filesystem is not configured for downloads.', ['X-Error-Code' => 'ATTACHMENT_DOWNLOAD_STORAGE_NOT_CONFIGURED']);
        }

        return $disk;
    }
}
