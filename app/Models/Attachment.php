<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AttachmentViewerCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'file_path',
    'file_name',
    'mime_type',
    'file_size',
    'viewer_category',
    'can_preview',
    'is_primary',
    'uploaded_by',
])]
class Attachment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewer_category' => AttachmentViewerCategory::class,
            'can_preview' => 'boolean',
            'is_primary' => 'boolean',
            'file_size' => 'integer',
        ];
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
