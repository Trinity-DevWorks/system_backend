<?php

declare(strict_types=1);

namespace App\Modules\Notification\DTOs;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * API shape for one in-app notification row.
 *
 * What: Maps Laravel DatabaseNotification + business payload to a stable frontend contract.
 * Used for: NotificationController list/mark-read responses.
 * Solves: Hides Laravel's class-name `type` column and exposes the business `type` from data.
 */
final class NotificationResponseData
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $severity,
        public readonly array $params,
        public readonly ?string $resourceType,
        public readonly ?string $resourceId,
        public readonly ?string $actionPath,
        public readonly bool $read,
        public readonly ?string $readAt,
        public readonly string $createdAt,
    ) {}

    public static function fromModel(DatabaseNotification $notification): self
    {
        $data = is_array($notification->data) ? $notification->data : [];

        return new self(
            id: (string) $notification->id,
            type: (string) ($data['type'] ?? $notification->type),
            severity: (string) ($data['severity'] ?? 'info'),
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            resourceType: isset($data['resource_type']) ? (string) $data['resource_type'] : null,
            resourceId: isset($data['resource_id']) ? (string) $data['resource_id'] : null,
            actionPath: isset($data['action_path']) ? (string) $data['action_path'] : null,
            read: $notification->read_at !== null,
            readAt: $notification->read_at?->toISOString(),
            createdAt: $notification->created_at?->toISOString() ?? now()->toISOString(),
        );
    }

    /**
     * @param  Collection<int, DatabaseNotification>  $items
     * @return list<array<string, mixed>>
     */
    public static function collectionToArray(Collection $items): array
    {
        return $items->map(fn (DatabaseNotification $n): array => self::fromModel($n)->toArray())->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'severity' => $this->severity,
            'params' => $this->params,
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
            'action_path' => $this->actionPath,
            'read' => $this->read,
            'read_at' => $this->readAt,
            'created_at' => $this->createdAt,
        ];
    }
}
