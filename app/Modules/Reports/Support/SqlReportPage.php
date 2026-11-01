<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Search, sort, totals and pagination for an analytics report query, all in the database.
 * The base query (a SELECT, or WITH ... SELECT) is wrapped as "r"; only one page of rows comes back.
 */
final class SqlReportPage
{
    public const MAX_PER_PAGE = 100;

    /**
     * @param  Builder|array{0: string, 1: list<mixed>}  $base
     * @param  list<string>  $searchExprs  text expressions over "r" matched with ILIKE
     * @param  array<string, string>  $sums  alias => SQL expression over "r" (totals over every matching row)
     * @return array{rows: list<array<string, mixed>>, sums: array<string, string>, total: int, page: int}
     */
    public static function run(
        Builder|array $base,
        ?string $search,
        array $searchExprs,
        string $orderBy,
        array $sums,
        ?int $page,
        ?int $perPage,
    ): array {
        [$sql, $bindings] = $base instanceof Builder ? [$base->toSql(), $base->getBindings()] : $base;

        $where = '';
        $whereBindings = [];
        if ($search !== null && $search !== '' && $searchExprs !== []) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $where = ' WHERE ('.implode(' OR ', array_map(fn (string $e): string => "{$e} ILIKE ?", $searchExprs)).')';
            $whereBindings = array_fill(0, count($searchExprs), $like);
        }

        $sumSql = '';
        foreach ($sums as $alias => $expr) {
            $sumSql .= ", COALESCE(SUM({$expr}), 0) AS {$alias}";
        }
        $agg = DB::selectOne("SELECT COUNT(*) AS n{$sumSql} FROM ({$sql}) r{$where}", [...$bindings, ...$whereBindings]);
        $total = (int) $agg->n;

        $limit = '';
        $current = 1;
        if ($perPage !== null) {
            $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
            $current = max(1, min($page ?? 1, max(1, (int) ceil($total / $perPage))));
            $limit = ' LIMIT '.$perPage.' OFFSET '.(($current - 1) * $perPage);
        }

        $rows = DB::select("SELECT r.* FROM ({$sql}) r{$where} ORDER BY {$orderBy}{$limit}", [...$bindings, ...$whereBindings]);

        return [
            'rows' => array_map(fn (object $row): array => get_object_vars($row), $rows),
            'sums' => array_map(fn ($v): string => (string) $v, array_intersect_key(get_object_vars($agg), $sums)),
            'total' => $total,
            'page' => $current,
        ];
    }

    /**
     * ORDER BY for a chosen sort key. Unknown keys use the default.
     *
     * @param  array<string, string>  $columns  sort key => SQL expression over "r"
     * @param  list<string>  $nullable  keys whose empty values sort lowest (first ascending, last descending)
     * @param  string  $ties  extra ORDER BY terms for stable paging
     */
    public static function orderBy(array $columns, string $sort, bool $desc, array $nullable, string $ties): string
    {
        $expr = $columns[$sort] ?? reset($columns);
        $dir = $desc ? 'DESC' : 'ASC';
        $nulls = in_array($sort, $nullable, true) ? ($desc ? ' NULLS LAST' : ' NULLS FIRST') : '';

        return "{$expr} {$dir}{$nulls}".($ties !== '' ? ", {$ties}" : '');
    }

    /**
     * Pagination block for the response; also tells the table which columns sort and the sort in use.
     *
     * @param  array{total: int, page: int, rows: list<mixed>}  $result
     * @param  list<string>  $sortable
     * @return array<string, mixed>
     */
    public static function meta(array $result, int $perPage, array $sortable, string $sort, bool $desc): array
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $count = count($result['rows']);
        $start = ($result['page'] - 1) * $perPage;

        return [
            'total' => $result['total'],
            'page' => $result['page'],
            'per_page' => $perPage,
            'from' => $count === 0 ? null : $start + 1,
            'to' => $count === 0 ? null : $start + $count,
            'sortable' => $sortable,
            'sort' => $sort,
            'dir' => $desc ? 'desc' : 'asc',
        ];
    }

    /**
     * Read sort / dir from the request list, falling back to the report default.
     *
     * @param  array{sort?: ?string, dir?: ?string}  $list
     * @param  list<string>  $sortable
     * @return array{0: string, 1: bool}
     */
    public static function sort(array $list, array $sortable, string $default, bool $defaultDesc): array
    {
        $sort = in_array($list['sort'] ?? null, $sortable, true) ? (string) $list['sort'] : $default;
        $dir = $list['dir'] ?? null;
        $desc = $dir !== null ? $dir === 'desc' : ($sort === $default && $defaultDesc);

        return [$sort, $desc];
    }
}
