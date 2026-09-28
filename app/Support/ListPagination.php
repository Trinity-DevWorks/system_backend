<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Shared server-side list pagination (page, per_page, search) for tenant index APIs.
 */
final class ListPagination
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 100;

    public static function perPage(Request $request, int $default = self::DEFAULT_PER_PAGE): int
    {
        return min(self::MAX_PER_PAGE, max(1, (int) $request->integer('per_page', $default)));
    }

    public static function search(Request $request, int $max = 255): ?string
    {
        $value = trim((string) $request->query('search', ''));
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * Case-insensitive contains match. PostgreSQL `LIKE` is case-sensitive.
     */
    private static function containsOperator(Builder $query): string
    {
        return $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * @param  list<string>  $columns
     * @param  array<string, list<string>>  $relations
     */
    public static function applySearch(Builder $query, ?string $search, array $columns, array $relations = []): Builder
    {
        if ($search === null || $search === '') {
            return $query;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $operator = self::containsOperator($query);

        return $query->where(function (Builder $q) use ($like, $columns, $relations, $operator): void {
            foreach ($columns as $index => $column) {
                if ($index === 0) {
                    $q->where($column, $operator, $like);
                } else {
                    $q->orWhere($column, $operator, $like);
                }
            }

            foreach ($relations as $relation => $relColumns) {
                $q->orWhereHas($relation, function (Builder $rq) use ($like, $relColumns, $operator): void {
                    $rq->where(function (Builder $inner) use ($like, $relColumns, $operator): void {
                        foreach ($relColumns as $index => $column) {
                            if ($index === 0) {
                                $inner->where($column, $operator, $like);
                            } else {
                                $inner->orWhere($column, $operator, $like);
                            }
                        }
                    });
                });
            }
        });
    }

    /**
     * @template TValue
     *
     * @param  LengthAwarePaginator<int, TValue>  $paginator
     * @param  callable(TValue): mixed  $map
     */
    public static function json(LengthAwarePaginator $paginator, callable $map, string $message): JsonResponse
    {
        $paginator->through($map);

        return ApiResponse::success($paginator->toArray(), $message);
    }

    /**
     * @template TValue
     *
     * @param  LengthAwarePaginator<int, TValue>  $paginator
     * @param  callable(Collection<int, TValue>): list<mixed>  $mapCollection
     */
    public static function jsonMapped(LengthAwarePaginator $paginator, callable $mapCollection, string $message): JsonResponse
    {
        $mapped = $mapCollection($paginator->getCollection());
        $paginator->setCollection(Collection::make($mapped));

        return ApiResponse::success($paginator->toArray(), $message);
    }
}
