<?php

declare(strict_types=1);

namespace App\Modules\Audit\DTOs;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * What: Readonly API payload for a single audit row (list + show).
 * Where: Built by AuditService / AuditController when returning JSON to clients.
 * Why: Keeps the HTTP contract stable and redacts structure (actor + entity + diffs)
 *      without exposing Eloquent internals.
 */
readonly class AuditResponseData
{
    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array{type: string|null, id: string|null, name: string|null, email: string|null}|null  $user
     * @param  array{type: string, id: string}|null  $auditable
     */
    public function __construct(
        public int $id,
        public string $event,
        public ?array $user,
        public ?array $auditable,
        public ?array $oldValues,
        public ?array $newValues,
        public ?string $url,
        public ?string $ipAddress,
        public ?string $userAgent,
        public ?string $tags,
        public string $createdAt,
    ) {}

    public static function fromModel(Audit $audit): self
    {
        $actor = $audit->user;
        $userPayload = null;

        if ($actor instanceof Model) {
            $userPayload = [
                'type' => $actor->getMorphClass(),
                'id' => (string) $actor->getKey(),
                'name' => isset($actor->name) ? (string) $actor->name : null,
                'email' => isset($actor->email) ? (string) $actor->email : null,
            ];
        }

        return new self(
            id: (int) $audit->getKey(),
            event: (string) $audit->event,
            user: $userPayload,
            auditable: [
                'type' => (string) $audit->auditable_type,
                'id' => (string) $audit->auditable_id,
            ],
            oldValues: is_array($audit->old_values) ? $audit->old_values : null,
            newValues: is_array($audit->new_values) ? $audit->new_values : null,
            url: $audit->url !== null ? (string) $audit->url : null,
            ipAddress: $audit->ip_address !== null ? (string) $audit->ip_address : null,
            userAgent: $audit->user_agent !== null ? (string) $audit->user_agent : null,
            tags: $audit->tags !== null ? (string) $audit->tags : null,
            createdAt: (string) $audit->created_at,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'user' => $this->user,
            'auditable' => $this->auditable,
            'old_values' => $this->oldValues,
            'new_values' => $this->newValues,
            'url' => $this->url,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
            'tags' => $this->tags,
            'created_at' => $this->createdAt,
        ];
    }
}
