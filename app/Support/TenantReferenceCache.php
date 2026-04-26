<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Reference list caching with explicit tenant key prefix (safe on any CACHE_STORE driver).
 */
final class TenantReferenceCache
{
    public static function scoped(string $key): string
    {
        if (function_exists('tenancy') && tenancy()->initialized) {
            return 'tn:'.tenant()->getTenantKey().':'.$key;
        }

        return 'app:'.$key;
    }

    public static function ttlSeconds(): int
    {
        return (int) config('cache.reference_ttl_seconds', 3600);
    }

    /**
     * Cache rows as plain arrays, then hydrate into models (avoids unserialize + allowed_classes issues for app models).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  Closure(): EloquentCollection<int, TModel>  $callback
     * @return EloquentCollection<int, TModel>
     */
    public static function rememberModels(string $key, string $modelClass, Closure $callback): EloquentCollection
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = Cache::remember(
            self::scoped($key),
            self::ttlSeconds(),
            function () use ($callback): array {
                return $callback()
                    ->map(fn (Model $m): array => $m->getAttributes())
                    ->values()
                    ->all();
            }
        );

        return $modelClass::hydrate($rows);
    }

    public static function forget(string ...$keys): void
    {
        foreach ($keys as $key) {
            Cache::forget(self::scoped($key));
        }
    }
}
