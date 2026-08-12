<?php

declare(strict_types=1);

namespace App\Modules\Rbac;

/**
 * Reads applicable RBAC actions from config/rbac.php.
 *
 * When adding import/export (or any) tenant routes, update that resource's
 * `actions` list in config/rbac.php in the same change.
 */
final class RbacResourceCatalog
{
    /** @var list<string> */
    public const ACTIONS = ['view', 'add', 'edit', 'delete', 'import', 'export'];

    /** @var array<string, string> */
    public const ACTION_FLAGS = [
        'view' => 'can_view',
        'add' => 'can_add',
        'edit' => 'can_edit',
        'delete' => 'can_delete',
        'import' => 'can_import',
        'export' => 'can_export',
    ];

    public static function label(string $resourceKey): string
    {
        $entry = config('rbac.resources.'.$resourceKey);

        if (is_array($entry) && isset($entry['label']) && is_string($entry['label']) && $entry['label'] !== '') {
            return $entry['label'];
        }

        if (is_string($entry) && $entry !== '') {
            return $entry;
        }

        return $resourceKey;
    }

    /**
     * @return list<string>
     */
    public static function actions(string $resourceKey): array
    {
        $entry = config('rbac.resources.'.$resourceKey);
        $known = array_flip(self::ACTIONS);

        if (is_array($entry) && isset($entry['actions']) && is_array($entry['actions'])) {
            $out = [];
            foreach ($entry['actions'] as $action) {
                if (! is_string($action) || ! isset($known[$action]) || isset($out[$action])) {
                    continue;
                }
                $out[$action] = $action;
            }

            if ($out !== []) {
                return array_values($out);
            }
        }

        return self::ACTIONS;
    }

    public static function allows(string $resourceKey, string $action): bool
    {
        return in_array($action, self::actions($resourceKey), true);
    }

    /**
     * Force flags that are not in the resource catalog to false.
     *
     * @param  array<string, mixed>  $row
     * @return array{can_view: bool, can_add: bool, can_edit: bool, can_delete: bool, can_import: bool, can_export: bool}
     */
    public static function clampFlags(string $resourceKey, array $row): array
    {
        $allowed = array_flip(self::actions($resourceKey));
        $out = [];
        foreach (self::ACTION_FLAGS as $action => $flag) {
            $out[$flag] = isset($allowed[$action]) && (bool) ($row[$flag] ?? false);
        }

        return $out;
    }
}
