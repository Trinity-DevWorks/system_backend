<?php

declare(strict_types=1);

namespace App\Modules\Notification\Support;

/**
 * Query object describing who should receive a notification.
 *
 * What: Immutable targeting criteria (explicit user ids and/or RBAC + branch).
 * Used for: RecipientResolver → NotificationDispatcher.
 * Solves: Keeps domain producers from writing ad-hoc user queries; targeting stays consistent.
 */
final class RecipientQuery
{
    /**
     * @param  list<string>|null  $userIds  Explicit user UUIDs (merged with permission matches).
     * @param  string|null  $permissionResource  RBAC resource_key (e.g. stock).
     * @param  string  $permissionAction  RBAC action (view|add|edit|...).
     * @param  int|null  $branchId  Limit permission matches to users assigned to this branch.
     * @param  bool  $activeOnly  Only is_active users.
     */
    public function __construct(
        public readonly ?array $userIds = null,
        public readonly ?string $permissionResource = null,
        public readonly string $permissionAction = 'view',
        public readonly ?int $branchId = null,
        public readonly bool $activeOnly = true,
    ) {}

    /**
     * @param  list<string>  $userIds
     */
    public static function users(array $userIds): self
    {
        return new self(userIds: array_values(array_unique(array_filter($userIds))));
    }

    public static function permission(string $resource, string $action = 'view', ?int $branchId = null): self
    {
        return new self(
            permissionResource: $resource,
            permissionAction: $action,
            branchId: $branchId,
        );
    }

    /**
     * @param  list<string>  $userIds
     */
    public static function usersAndPermission(
        array $userIds,
        string $resource,
        string $action = 'view',
        ?int $branchId = null,
    ): self {
        return new self(
            userIds: array_values(array_unique(array_filter($userIds))),
            permissionResource: $resource,
            permissionAction: $action,
            branchId: $branchId,
        );
    }
}
