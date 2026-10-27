<?php

declare(strict_types=1);

namespace App\Modules\Branch\Support;

use App\Modules\Branch\Models\Branch;
use Illuminate\Database\Eloquent\Model;

/**
 * API fields for a document's stored branch (docs/printing-plan.md D3).
 */
final class DocumentBranchPayload
{
    /** @var array<string, Branch|null> */
    private static array $cache = [];

    /**
     * @return array{branch_id:int|null,branch:array{id:int,name:string,shortcut_name:string|null}|null}
     */
    public static function of(Model $document, string $column = 'branch_id', string $relation = 'branch'): array
    {
        $branchId = $document->getAttribute($column);
        $branch = null;
        if ($branchId !== null) {
            $branch = $document->relationLoaded($relation)
                ? $document->getRelation($relation)
                : self::lookup((int) $branchId);
        }

        return [
            $column => $branchId !== null ? (int) $branchId : null,
            $relation => $branch instanceof Branch ? [
                'id' => (int) $branch->id,
                'name' => (string) $branch->name,
                'shortcut_name' => $branch->shortcut_name,
            ] : null,
        ];
    }

    /**
     * Lists render many documents of few branches; read each branch once per tenant and request.
     */
    private static function lookup(int $branchId): ?Branch
    {
        $key = (string) (function_exists('tenant') ? tenant()?->getTenantKey() : '').':'.$branchId;
        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = Branch::query()->find($branchId, ['id', 'name', 'shortcut_name']);
        }

        return self::$cache[$key];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
