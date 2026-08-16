<?php

declare(strict_types=1);

namespace Rocket\Query;

/**
 * Applies request-driven filtering, sorting and pagination to a query.
 *
 * Every method here is fed by untrusted input, so the column a request may
 * touch is always chosen from a developer-supplied map or allowlist — never
 * taken from the request itself.
 *
 * @package Rocket\Query
 */
class QueryFilter
{
    /**
     * Default page size when a request asks for pagination without a limit.
     */
    public const DEFAULT_LIMIT = 25;

    /**
     * Hard ceiling on page size, so a request cannot ask for the whole table.
     */
    public const MAX_LIMIT = 100;

    /**
     * Apply a filter map to a query.
     *
     * Each entry maps a request parameter to the column(s) it filters and the
     * operator to use:
     *
     * ```php
     * QueryFilter::apply($query, [
     *     'status' => ['column' => 'status', 'values' => ['open', 'closed']],
     *     'q'      => ['column' => ['title', 'body'], 'operator' => 'LIKE'],
     * ], $request->query());
     * ```
     *
     * @param QueryBuilder                      $query   Query to modify
     * @param array<string, array<string,mixed>> $filters Parameter to column map
     * @param array<string, mixed>              $params  Request parameters
     */
    public static function apply(QueryBuilder $query, array $filters, array $params): QueryBuilder
    {
        foreach ($filters as $parameter => $config) {
            $value = $params[$parameter] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            // When the developer declared an allowlist, anything outside it is
            // dropped rather than passed through to the database.
            if (isset($config['values']) && !in_array($value, $config['values'], true)) {
                continue;
            }

            $columns = (array) ($config['column'] ?? $parameter);
            $operator = $config['operator'] ?? '=';

            if ($operator === 'LIKE') {
                self::applyLike($query, $columns, (string) $value);
                continue;
            }

            $query->where((string) $columns[0], $operator, $value);
        }

        return $query;
    }

    /**
     * Apply a LIKE search across one or more columns.
     *
     * @param QueryBuilder $query   Query to modify
     * @param list<string> $columns Columns to search
     * @param string       $value   Raw search term
     */
    private static function applyLike(QueryBuilder $query, array $columns, string $value): void
    {
        // Escape the LIKE metacharacters so a search for "100%" does not become
        // a wildcard that matches every row.
        $pattern = '%' . addcslashes($value, '%_\\') . '%';

        if (count($columns) === 1) {
            $query->where($columns[0], 'LIKE', $pattern);

            return;
        }

        $query->where(static function (QueryBuilder $nested) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $nested->orWhere($column, 'LIKE', $pattern);
            }
        });
    }

    /**
     * Apply pagination from request parameters.
     *
     * The limit is clamped so a request cannot force the database to return an
     * unbounded result set.
     *
     * @param QueryBuilder         $query  Query to modify
     * @param array<string, mixed> $params Request parameters, read from `limit` and `offset` or `page`
     */
    public static function paginate(QueryBuilder $query, array $params): QueryBuilder
    {
        $limit = (int) ($params['limit'] ?? self::DEFAULT_LIMIT);
        $limit = max(1, min($limit, self::MAX_LIMIT));

        $offset = isset($params['page'])
            ? (max(1, (int) $params['page']) - 1) * $limit
            : max(0, (int) ($params['offset'] ?? 0));

        return $query->limit($limit)->offset($offset);
    }

    /**
     * Apply sorting chosen by the request, restricted to an allowlist.
     *
     * This is the method a `?sort=` parameter should reach. The column is looked
     * up in `$sortable` rather than passed through, so an unknown or hostile
     * value falls back to the default instead of reaching SQL.
     *
     * @param QueryBuilder         $query    Query to modify
     * @param array<string, mixed> $params   Request parameters, read from `sort` and `direction`
     * @param list<string>         $sortable Columns the request is allowed to sort by
     * @param string|null          $default  Column used when the request names none
     */
    public static function sort(
        QueryBuilder $query,
        array $params,
        array $sortable,
        ?string $default = null
    ): QueryBuilder {
        $requested = (string) ($params['sort'] ?? '');
        $column = in_array($requested, $sortable, true) ? $requested : $default;

        if ($column === null) {
            return $query;
        }

        $direction = strtoupper((string) ($params['direction'] ?? 'ASC'));
        $direction = $direction === 'DESC' ? 'DESC' : 'ASC';

        return $query->orderBy($column, $direction);
    }

    /**
     * Apply a fixed sort order chosen by the developer.
     *
     * @param QueryBuilder          $query   Query to modify
     * @param array<string, string> $orderBy Column to direction map
     */
    public static function orderBy(QueryBuilder $query, array $orderBy): QueryBuilder
    {
        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Collect the filter, sort and pagination values a request supplied.
     *
     * Useful for echoing the applied filters back in an API response.
     *
     * @param array<string, array<string,mixed>> $filters Parameter to column map
     * @param array<string, mixed>               $params  Request parameters
     *
     * @return array<string, mixed>
     */
    public static function getParams(array $filters, array $params): array
    {
        $result = [];

        foreach (array_keys($filters) as $parameter) {
            $result[$parameter] = $params[$parameter] ?? null;
        }

        $result['limit'] = max(1, min((int) ($params['limit'] ?? self::DEFAULT_LIMIT), self::MAX_LIMIT));
        $result['offset'] = max(0, (int) ($params['offset'] ?? 0));

        return $result;
    }
}
