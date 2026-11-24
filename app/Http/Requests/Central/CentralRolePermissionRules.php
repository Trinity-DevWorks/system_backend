<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

/**
 * Shared `permissions.*` rules for central role requests (same row shape as tenant roles).
 */
final class CentralRolePermissionRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(string $presence): array
    {
        return [
            'permissions' => [$presence, 'array'],
            'permissions.*.permission_id' => ['required', 'integer', 'exists:central_permissions,id'],
            'permissions.*.can_view' => ['required', 'boolean'],
            'permissions.*.can_add' => ['required', 'boolean'],
            'permissions.*.can_edit' => ['required', 'boolean'],
            'permissions.*.can_delete' => ['required', 'boolean'],
            'permissions.*.can_import' => ['required', 'boolean'],
            'permissions.*.can_export' => ['required', 'boolean'],
            'permissions.*.can_reverse' => ['required', 'boolean'],
        ];
    }
}
