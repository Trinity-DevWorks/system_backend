<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AttachmentViewerCategory;
use Illuminate\Http\UploadedFile;

final class AttachmentClassifier
{
    /**
     * @return array{viewer_category: AttachmentViewerCategory, mime_type: string, can_preview: bool}
     */
    public static function fromUploadedFile(UploadedFile $file): array
    {
        $mime = strtolower((string) $file->getMimeType());
        $name = $file->getClientOriginalName() ?: 'upload';
        $ext = self::extensionFromName($name);

        if ($mime === '' && $ext !== '') {
            $mime = self::guessMimeFromExtension($ext);
        }

        return self::classify($mime, $ext);
    }

    /**
     * @return array{viewer_category: AttachmentViewerCategory, mime_type: string, can_preview: bool}
     */
    public static function classify(string $mime, string $extension): array
    {
        $mime = strtolower($mime);
        $ext = strtolower($extension);

        $category = self::resolveCategory($mime, $ext);
        $canPreview = $category->documentCanPreview($ext);

        if ($mime === '' && $ext !== '') {
            $mime = self::guessMimeFromExtension($ext);
        }

        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        return [
            'viewer_category' => $category,
            'mime_type' => $mime,
            'can_preview' => $canPreview,
        ];
    }

    private static function resolveCategory(string $mime, string $ext): AttachmentViewerCategory
    {
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return AttachmentViewerCategory::Pdf;
        }

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return AttachmentViewerCategory::Image;
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov'], true)) {
            return AttachmentViewerCategory::Video;
        }

        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg'], true)) {
            return AttachmentViewerCategory::Audio;
        }

        $textExts = ['json', 'xml', 'csv', 'log', 'sql', 'txt'];
        $textMimes = [
            'application/json',
            'application/xml',
            'text/xml',
            'text/csv',
            'text/plain',
            'application/sql',
        ];
        if (in_array($ext, $textExts, true) || in_array($mime, $textMimes, true) || str_starts_with($mime, 'text/')) {
            return AttachmentViewerCategory::Text;
        }

        $docExts = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'];
        $docMimes = [
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        ];
        if (in_array($ext, $docExts, true) || in_array($mime, $docMimes, true)) {
            return AttachmentViewerCategory::Document;
        }

        return AttachmentViewerCategory::Other;
    }

    private static function extensionFromName(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: '');
    }

    private static function guessMimeFromExtension(string $ext): string
    {
        return match ($ext) {
            'pdf' => 'application/pdf',
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'csv' => 'text/csv',
            'txt', 'log', 'sql' => 'text/plain',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }
}
