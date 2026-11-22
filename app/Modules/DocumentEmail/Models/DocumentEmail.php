<?php

declare(strict_types=1);

namespace App\Modules\DocumentEmail\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One email sent (or tried) for a document.
 *
 * @property string $id
 * @property string $document_type
 * @property string $document_id
 * @property string|null $document_number
 * @property list<string> $to
 * @property list<string>|null $cc
 * @property string $subject
 * @property string $body
 * @property string $locale
 * @property string|null $attachment_name
 * @property string $status queued | sending | sent | failed
 * @property string|null $error
 * @property int $attempts
 * @property Carbon|null $sent_at
 * @property array<string, mixed>|null $meta
 * @property string|null $user_id
 * @property Carbon $created_at
 * @property-read User|null $user
 */
#[Fillable([
    'document_type',
    'document_id',
    'document_number',
    'to',
    'cc',
    'subject',
    'body',
    'locale',
    'attachment_name',
    'status',
    'error',
    'attempts',
    'sent_at',
    'meta',
    'user_id',
])]
class DocumentEmail extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    /** A worker is sending it right now. */
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'meta' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toResponse(): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_id' => $this->document_id,
            'document_number' => $this->document_number,
            'to' => $this->to,
            'cc' => $this->cc ?? [],
            'subject' => $this->subject,
            'body' => $this->body,
            'locale' => $this->locale,
            'attachment_name' => $this->attachment_name,
            'status' => $this->status,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'meta' => $this->meta,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
        ];
    }
}
