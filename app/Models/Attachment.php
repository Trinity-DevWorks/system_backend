<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentProcessingStatus;
use App\Enums\AttachmentScanStatus;
use App\Enums\AttachmentViewerCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

#[Fillable([
    'disk',
    'file_path',
    'file_name',
    'mime_type',
    'file_size',
    'checksum',
    'checksum_algo',
    'viewer_category',
    'can_preview',
    'is_primary',
    'processing_status',
    'scan_status',
    'scan_signature',
    'scanned_at',
    'uploaded_by',
])]
class Attachment extends Model implements AuditableContract
{
    use Auditable;
    use HasUuids;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewer_category' => AttachmentViewerCategory::class,
            'processing_status' => AttachmentProcessingStatus::class,
            'scan_status' => AttachmentScanStatus::class,
            'can_preview' => 'boolean',
            'is_primary' => 'boolean',
            'file_size' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    public function isReady(): bool
    {
        return $this->processing_status === AttachmentProcessingStatus::Ready;
    }

    public function isDownloadable(): bool
    {
        return $this->isReady()
            && $this->scan_status !== AttachmentScanStatus::Infected
            && $this->scan_status !== AttachmentScanStatus::Failed;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
