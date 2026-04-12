<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Attachment;
use Illuminate\Support\Collection;

readonly class AttachmentResponseData
{
    public function __construct(
        public int $id,
        public string $attachableType,
        public int $attachableId,
        public string $fileName,
        public string $fileType,
        public ?int $uploadedBy,
        public string $downloadUrl,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    public static function fromModel(Attachment $attachment, string $downloadUrl): self
    {
        return new self(
            id: $attachment->id,
            attachableType: (string) $attachment->attachable_type,
            attachableId: (int) $attachment->attachable_id,
            fileName: $attachment->file_name,
            fileType: $attachment->file_type,
            uploadedBy: $attachment->uploaded_by !== null ? (int) $attachment->uploaded_by : null,
            downloadUrl: $downloadUrl,
            createdAt: (string) $attachment->created_at,
            updatedAt: (string) $attachment->updated_at,
        );
    }

    /**
     * @param  Collection<int, Attachment>  $rows
     * @param  callable(Attachment): string  $downloadUrlFor
     * @return array<int, array<string, mixed>>
     */
    public static function collectionToArray(Collection $rows, callable $downloadUrlFor): array
    {
        return $rows
            ->map(fn (Attachment $a): array => self::fromModel($a, $downloadUrlFor($a))->toArray())
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
            'file_type' => $this->fileType,
            'uploaded_by' => $this->uploadedBy,
            'download_url' => $this->downloadUrl,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
