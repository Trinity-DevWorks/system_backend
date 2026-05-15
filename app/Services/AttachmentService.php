<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Modules\Customer\Models\Customer;
use App\Modules\Salesman\Models\Salesman;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AttachmentService
{
    /**
     * @return Collection<int, Attachment>
     */
    public function listFor(Customer|Supplier|Salesman $attachable): Collection
    {
        return $attachable->attachments()->orderByDesc('id')->get();
    }

    public function store(Customer|Supplier|Salesman $attachable, UploadedFile $file, ?int $uploadedByUserId): Attachment
    {
        return DB::transaction(function () use ($attachable, $file, $uploadedByUserId): Attachment {
            $original = $file->getClientOriginalName() ?: 'upload';
            $safeBase = Str::slug(pathinfo($original, PATHINFO_FILENAME)) ?: 'file';
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
            $storedName = $safeBase.'_'.Str::uuid()->toString().'.'.$ext;

            $dir = 'attachments/'.$attachable->getMorphClass().'/'.$attachable->getKey();
            $path = $file->storeAs($dir, $storedName, 'local');

            return $attachable->attachments()->create([
                'file_path' => $path,
                'file_name' => $original,
                'file_type' => $this->detectFileType($file),
                'uploaded_by' => $uploadedByUserId,
            ]);
        });
    }

    public function delete(Attachment $attachment): void
    {
        DB::transaction(function () use ($attachment): void {
            if (Storage::disk('local')->exists($attachment->file_path)) {
                Storage::disk('local')->delete($attachment->file_path);
            }
            $attachment->delete();
        });
    }

    public function assertStoredFileExists(Attachment $attachment): void
    {
        if (! Storage::disk('local')->exists($attachment->file_path)) {
            abort(404, 'File not found on storage.');
        }
    }

    private function detectFileType(UploadedFile $file): string
    {
        $mime = strtolower((string) $file->getMimeType());
        $ext = strtolower((string) $file->getClientOriginalExtension());

        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return 'pdf';
        }

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        $docMimes = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        if (in_array($mime, $docMimes, true)) {
            return 'document';
        }

        return 'other';
    }
}
