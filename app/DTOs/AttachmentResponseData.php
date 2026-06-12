<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Attachment;
use Illuminate\Support\Collection;

readonly class AttachmentResponseData
{
    public function __construct(
        public string $id,
        public string $attachableType,
        public string $attachableId,
        public string $fileName,
        public string $mimeType,
        public int $fileSize,
        public string $viewerCategory,
        public bool $canPreview,
        public bool $isPrimary,
        public ?string $uploadedBy,
        public string $downloadUrl,
        public string $viewUrl,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Attachment $attachment, string $downloadUrl, string $viewUrl): self
    {
        return new self(
            id: $attachment->id,
            attachableType: (string) $attachment->attachable_type,
            attachableId: (string) $attachment->attachable_id,
            fileName: $attachment->file_name,
            mimeType: $attachment->mime_type,
            fileSize: (int) $attachment->file_size,
            viewerCategory: $attachment->viewer_category->value,
            canPreview: (bool) $attachment->can_preview,
            isPrimary: (bool) $attachment->is_primary,
            uploadedBy: $attachment->uploaded_by !== null ? (string) $attachment->uploaded_by : null,
            downloadUrl: $downloadUrl,
            viewUrl: $viewUrl,
            createdAt: (string) $attachment->created_at,
            updatedAt: (string) $attachment->updated_at,
        );
    }

    /**
     * @param  Collection<int, Attachment>  $rows
     * @param  callable(Attachment): array{download: string, view: string}  $urlsFor
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows, callable $urlsFor): array
    {
        return $rows
            ->map(function (Attachment $a) use ($urlsFor): array {
                $urls = $urlsFor($a);

                return self::fromModel($a, $urls['download'], $urls['view'])->toArray();
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'attachable_type' => $this->attachableType,
            'attachable_id' => $this->attachableId,
            'file_name' => $this->fileName,
            'mime_type' => $this->mimeType,
            'file_size' => $this->fileSize,
            'viewer_category' => $this->viewerCategory,
            'can_preview' => $this->canPreview,
            'is_primary' => $this->isPrimary,
            'uploaded_by' => $this->uploadedBy,
            'download_url' => $this->downloadUrl,
            'view_url' => $this->viewUrl,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
