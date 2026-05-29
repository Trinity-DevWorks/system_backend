<?php

declare(strict_types=1);

namespace App\Enums;

enum AttachmentViewerCategory: string
{
    case Image = 'image';
    case Pdf = 'pdf';
    case Document = 'document';
    case Video = 'video';
    case Audio = 'audio';
    case Text = 'text';
    case Other = 'other';

    public function canPreview(): bool
    {
        return match ($this) {
            self::Image, self::Pdf, self::Video, self::Audio, self::Text => true,
            self::Document, self::Other => false,
        };
    }

    /** Document subtypes that support in-browser preview. */
    public function documentCanPreview(string $extension): bool
    {
        if ($this !== self::Document) {
            return $this->canPreview();
        }

        return in_array($extension, ['docx', 'xlsx', 'txt'], true);
    }
}
