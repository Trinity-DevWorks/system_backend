<?php

declare(strict_types=1);

namespace App\Support;

use App\Modules\Rbac\RbacResourceCatalog;

/**
 * Reads applicable central RBAC actions from config/central_rbac.php.
 *
 * Mirrors {@see RbacResourceCatalog} for the tenant catalog; action flags are shared.
 */
final class CentralRbacResourceCatalog
{
    /**
     * @return list<string>
     */
    public static function resourceKeys(): array
    {
        return array_values(array_map('strval', array_keys((array) config('central_rbac.resources', []))));
    }

    public static function label(string $resourceKey): string
    {
        $entry = config('central_rbac.resources.'.$resourceKey);

        if (is_array($entry) && isset($entry['label']) && is_string($entry['label']) && $entry['label'] !== '') {
            return $entry['label'];
        }

        return $resourceKey;
    }

    /**
     * @return list<string>
     */
    public static function actions(string $resourceKey): array
    {
        $entry = config('central_rbac.resources.'.$resourceKey);
        if (! is_array($entry) || ! isset($entry['actions']) || ! is_array($entry['actions'])) {
            return [];
        }

        $known = array_flip(RbacResourceCatalog::ACTIONS);
        $out = [];
        foreach ($entry['actions'] as $action) {
            if (! is_string($action) || ! isset($known[$action]) || isset($out[$action])) {
                continue;
            }
            $out[$action] = $action;
        }

        return array_values($out);
    }

    public static function allows(string $resourceKey, string $action): bool
    {
        return in_array($action, self::actions($resourceKey), true);
    }

    /**
     * Force flags that are not in the resource catalog to false.
     *
     * @param  array<string, mixed>  $row
     * @return array{can_view: bool, can_add: bool, can_edit: bool, can_delete: bool, can_import: bool, can_export: bool, can_reverse: bool}
     */
    public static function clampFlags(string $resourceKey, array $row): array
    {
        $allowed = array_flip(self::actions($resourceKey));
        $out = [];
        foreach (RbacResourceCatalog::ACTION_FLAGS as $action => $flag) {
            $out[$flag] = isset($allowed[$action]) && (bool) ($row[$flag] ?? false);
        }

        /** @var array{can_view: bool, can_add: bool, can_edit: bool, can_delete: bool, can_import: bool, can_export: bool, can_reverse: bool} $out */
        return $out;
    }

    /**
     * Every catalogued flag set to true (Super Admin matrix).
     *
     * @return array<string, array<string, bool>>
     */
    public static function fullMatrix(): array
    {
        $matrix = [];
        foreach (self::resourceKeys() as $resourceKey) {
            foreach (self::actions($resourceKey) as $action) {
                $matrix[$resourceKey][RbacResourceCatalog::ACTION_FLAGS[$action]] = true;
            }
        }

        return $matrix;
    }
}
