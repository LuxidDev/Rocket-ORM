<?php

declare(strict_types=1);

namespace Rocket\Query;

use Closure;
use Rocket\Connection\Connection;
use Rocket\ORM\Entity;

/**
 * Fluent SELECT builder.
 *
 * Values are always bound as parameters. Identifiers and operators cannot be
 * bound by any database driver, so they are validated against a strict pattern
 * and a fixed allowlist before they reach SQL — otherwise a `?sort=` parameter
 * handed to `orderBy()` would be an injection point.
 *
 * @template TEntity of Entity
 *
 * @package Rocket\Query
 */
class QueryBuilder
{
    /**
     * Comparison operators permitted in a WHERE clause.
     *
     * @var list<string>
     */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IS', 'IS NOT'];

    /**
     * The same operators as a lookup set, so validation is a hash hit.
     */
    private const OPERATOR_SET = [
        '=' => true, '!=' => true, '<>' => true, '<' => true, '<=' => true,
        '>' => true, '>=' => true, 'LIKE' => true, 'NOT LIKE' => true,
        'IS' => true, 'IS NOT' => true,
    ];

    /**
     * Sort directions permitted in an ORDER BY clause.
     *
     * @var list<string>
     */
    private const DIRECTIONS = ['ASC', 'DESC'];

    /**
     * Process-wide counter guaranteeing unique placeholder names.
     *
     * Nested builders previously restarted numbering at zero and their bindings
     * overwrote the parent's when merged, silently changing the query's meaning.
     */
    private static int $parameterSequence = 0;

    /**
     * Identifiers already validated, mapped to their trimmed form.
     *
     * @var array<string, string>
     */
    private static array $validIdentifiers = [];

    /**
     * Entity class rows hydrate into.
     *
     * @var class-string<TEntity>
     */
    protected string $entityClass;

    /**
     * Table being queried.
     */
    protected string $table;

    /**
     * Columns to select.
     *
     * @var list<string>
     */
    protected array $select = ['*'];

    /**
     * Rendered conditions in declaration order, each with its connector.
     *
     * Kept as one ordered list rather than separate AND and OR buckets. Two
     * buckets cannot express the order the caller wrote, so a closure mixing
     * `where()` and `orWhere()` produced `a AND (b)` instead of `a OR b` and
     * silently matched nothing.
     *
     * @var list<array{boolean: string, sql: string}>
     */
    protected array $conditions = [];

    /**
     * Rendered ORDER BY terms.
     *
     * @var list<string>
     */
    protected array $orderBy = [];

    /**
     * Rendered GROUP BY columns.
     *
     * @var list<string>
     */
    protected array $groupBy = [];

    /**
     * Maximum number of rows to return.
     */
    protected ?int $limit = null;

    /**
     * Number of rows to skip.
     */
    protected ?int $offset = null;

    /**
     * Parameter bindings keyed by placeholder name.
     *
     * @var array<string, mixed>
     */
    protected array $bindings = [];

    /**
     * @param class-string<TEntity> $entityClass Entity rows hydrate into
     */
    public function __construct(string $entityClass)
    {
        $this->entityClass = $entityClass;
        $this->table = $this->assertIdentifier($entityClass::tableName());
    }

    /**
     * Restrict the selected columns.
     *
     * @param list<string> $columns Column names, or `['*']`
     *
     * @throws \InvalidArgumentException When a column name is not a valid identifier
     */
    public function select(array $columns): self
    {
        $this->select = array_map($this->assertIdentifier(...), $columns);

        return $this;
    }

    /**
     * Add an AND condition.
     *
     * Accepts `where('status', 'pending')`, `where('age', '>=', 18)` or a closure
     * receiving a nested builder.
     *
     * @param string|Closure(self): void $column   Column name or nested condition
     * @param mixed                      $operator Operator, or the value in the two-argument form
     * @param mixed                      $value    Value to compare against
     *
     * @throws \InvalidArgumentException When the column or operator is not valid
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            $nested = $this->nest($column);

            if ($nested !== null) {
                $this->conditions[] = ['boolean' => 'AND', 'sql' => $nested];
            }

            return $this;
        }

        [$operator, $value] = $this->normalizeComparison(func_num_args(), $operator, $value);

        $this->conditions[] = ['boolean' => 'AND', 'sql' => $this->condition($column, $operator, $value)];

        return $this;
    }

    /**
     * Add an OR condition.
     *
     * @param string|Closure(self): void $column   Column name or nested condition
     * @param mixed                      $operator Operator, or the value in the two-argument form
     * @param mixed                      $value    Value to compare against
     *
     * @throws \InvalidArgumentException When the column or operator is not valid
     */
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            $nested = $this->nest($column);

            if ($nested !== null) {
                $this->conditions[] = ['boolean' => 'OR', 'sql' => $nested];
            }

            return $this;
        }

        [$operator, $value] = $this->normalizeComparison(func_num_args(), $operator, $value);

        $this->conditions[] = ['boolean' => 'OR', 'sql' => $this->condition($column, $operator, $value)];

        return $this;
    }

    /**
     * Restrict a column to a set of values.
     *
     * @param string       $column Column name
     * @param list<mixed>  $values Permitted values
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function whereIn(string $column, array $values): self
    {
        $column = $this->assertIdentifier($column);

        if ($values === []) {
            // An empty IN list is invalid SQL; a contradiction preserves intent.
            $this->conditions[] = ['boolean' => 'AND', 'sql' => '1 = 0'];

            return $this;
        }

        $placeholders = [];

        foreach ($values as $value) {
            $placeholders[] = ':' . $this->bind($column, $value);
        }

        $this->conditions[] = ['boolean' => 'AND', 'sql' => sprintf('%s IN (%s)', $column, implode(', ', $placeholders))];

        return $this;
    }

    /**
     * Exclude a set of values for a column.
     *
     * @param string      $column Column name
     * @param list<mixed> $values Excluded values
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function whereNotIn(string $column, array $values): self
    {
        $column = $this->assertIdentifier($column);

        if ($values === []) {
            return $this;
        }

        $placeholders = [];

        foreach ($values as $value) {
            $placeholders[] = ':' . $this->bind($column, $value);
        }

        $this->conditions[] = ['boolean' => 'AND', 'sql' => sprintf('%s NOT IN (%s)', $column, implode(', ', $placeholders))];

        return $this;
    }

    /**
     * Restrict a column to an inclusive range.
     *
     * @param string $column Column name
     * @param mixed  $from   Lower bound
     * @param mixed  $to     Upper bound
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $column = $this->assertIdentifier($column);

        $this->conditions[] = [
            'boolean' => 'AND',
            'sql' => sprintf(
                '%s BETWEEN :%s AND :%s',
                $column,
                $this->bind($column, $from),
                $this->bind($column, $to)
            ),
        ];

        return $this;
    }

    /**
     * Match a column against a LIKE pattern.
     *
     * @param string $column  Column name
     * @param string $pattern LIKE pattern, wildcards included by the caller
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function whereLike(string $column, string $pattern): self
    {
        return $this->where($column, 'LIKE', $pattern);
    }

    /**
     * Require a column to be NULL.
     *
     * @param string $column Column name
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function whereNull(string $column): self
    {
        $this->conditions[] = ['boolean' => 'AND', 'sql' => $this->assertIdentifier($column) . ' IS NULL'];

        return $this;
    }

    /**
     * Require a column to be non-NULL.
     *
     * @param string $column Column name
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function whereNotNull(string $column): self
    {
        $this->conditions[] = ['boolean' => 'AND', 'sql' => $this->assertIdentifier($column) . ' IS NOT NULL'];

        return $this;
    }

    /**
     * Add a raw condition.
     *
     * The deliberate escape hatch for expressions this builder cannot model.
     * The SQL fragment is trusted verbatim, so never build it from request data;
     * pass values through `$bindings` instead.
     *
     * @param string               $sql      SQL fragment, using named placeholders
     * @param array<string, mixed> $bindings Values for the placeholders
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        $this->conditions[] = ['boolean' => 'AND', 'sql' => '(' . $sql . ')'];
        $this->bindings = array_merge($this->bindings, $bindings);

        return $this;
    }

    /**
     * Sort by a column.
     *
     * @param string $column    Column name
     * @param string $direction `ASC` or `DESC`
     *
     * @throws \InvalidArgumentException When the column or direction is not valid
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper(trim($direction));

        if (!in_array($direction, self::DIRECTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid sort direction "%s"', $direction));
        }

        $this->orderBy[] = $this->assertIdentifier($column) . ' ' . $direction;

        return $this;
    }

    /**
     * Sort rows randomly.
     */
    public function inRandomOrder(): self
    {
        $this->orderBy[] = 'RAND()';

        return $this;
    }

    /**
     * Group results by a column.
     *
     * @param string $column Column name
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function groupBy(string $column): self
    {
        $this->groupBy[] = $this->assertIdentifier($column);

        return $this;
    }

    /**
     * Limit the number of rows returned.
     *
     * @param int $limit Maximum rows; negative values are clamped to zero
     */
    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    /**
     * Skip a number of rows.
     *
     * @param int $offset Rows to skip; negative values are clamped to zero
     */
    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Get the first matching row.
     *
     * @return TEntity|null
     */
    public function first(): ?Entity
    {
        return $this->limit(1)->all()[0] ?? null;
    }

    /**
     * Get every matching row.
     *
     * @return list<TEntity>
     */
    public function all(): array
    {
        $entityClass = $this->entityClass;

        return $entityClass::hydrateMany(
            Connection::getInstance()->query($this->buildSelect(), $this->bindings)
        );
    }

    /**
     * Get a single column from every matching row.
     *
     * @param string $column Column name
     *
     * @return list<mixed>
     *
     * @throws \InvalidArgumentException When the column name is not a valid identifier
     */
    public function pluck(string $column): array
    {
        $column = $this->assertIdentifier($column);
        $rows = Connection::getInstance()->query(
            $this->select([$column])->buildSelect(),
            $this->bindings
        );

        return array_column($rows, $column);
    }

    /**
     * Count the matching rows.
     */
    public function count(): int
    {
        $rows = Connection::getInstance()->query($this->buildCount(), $this->bindings);

        return (int) ($rows[0]['aggregate'] ?? 0);
    }

    /**
     * Check whether any row matches.
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Render the SELECT statement.
     */
    public function toSql(): string
    {
        return $this->buildSelect();
    }

    /**
     * Get the parameter bindings for the rendered statement.
     *
     * @return array<string, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get the rendered conditions in declaration order.
     *
     * @return list<array{boolean: string, sql: string}>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Get the conditions joined with AND.
     *
     * @return list<string>
     */
    public function getWhereConditions(): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['sql'],
            array_filter($this->conditions, static fn (array $c): bool => $c['boolean'] === 'AND')
        ));
    }

    /**
     * Get the conditions joined with OR.
     *
     * @return list<string>
     */
    public function getOrWhereConditions(): array
    {
        return array_values(array_map(
            static fn (array $c): string => $c['sql'],
            array_filter($this->conditions, static fn (array $c): bool => $c['boolean'] === 'OR')
        ));
    }

    /**
     * Join an ordered condition list into a SQL fragment.
     *
     * The first condition's connector is dropped, so a list that starts with an
     * `orWhere()` still renders as a plain leading term.
     *
     * @param list<array{boolean: string, sql: string}> $conditions Ordered conditions
     */
    private static function renderConditions(array $conditions): string
    {
        $sql = '';

        foreach ($conditions as $index => $condition) {
            $sql .= $index === 0
                ? $condition['sql']
                : ' ' . $condition['boolean'] . ' ' . $condition['sql'];
        }

        return $sql;
    }

    /**
     * Build a parenthesised condition from a nested builder.
     *
     * Bindings from the nested builder are merged into this one; placeholder
     * names are globally unique, so nothing can collide.
     *
     * @param Closure(self): void $callback Receives the nested builder
     *
     * @return string|null The rendered group, or null when it added no conditions
     */
    private function nest(Closure $callback): ?string
    {
        $nested = new self($this->entityClass);
        $callback($nested);

        $this->bindings = array_merge($this->bindings, $nested->getBindings());
        $rendered = self::renderConditions($nested->getConditions());

        return $rendered === '' ? null : '(' . $rendered . ')';
    }

    /**
     * Render a single comparison and bind its value.
     *
     * @param string $column   Column name
     * @param string $operator Comparison operator
     * @param mixed  $value    Value to compare against
     *
     * @throws \InvalidArgumentException When the column or operator is not valid
     */
    private function condition(string $column, string $operator, mixed $value): string
    {
        $column = $this->assertIdentifier($column);

        return $column . ' ' . $operator . ' :' . $this->bind($column, $value);
    }

    /**
     * Resolve the two- and three-argument comparison forms.
     *
     * @param int   $argumentCount Number of arguments the caller supplied
     * @param mixed $operator      Operator, or the value in the two-argument form
     * @param mixed $value         Value to compare against
     *
     * @return array{0: string, 1: mixed}
     *
     * @throws \InvalidArgumentException When the operator is not allowlisted
     */
    private function normalizeComparison(int $argumentCount, mixed $operator, mixed $value): array
    {
        if ($argumentCount === 2) {
            return ['=', $operator];
        }

        // Symbolic operators are already canonical; only word operators such as
        // `like` need normalising.
        if (isset(self::OPERATOR_SET[$operator])) {
            return [$operator, $value];
        }

        $normalized = strtoupper(trim((string) $operator));

        if (!isset(self::OPERATOR_SET[$normalized])) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s"', $operator));
        }

        return [$normalized, $value];
    }

    /**
     * Register a binding under a unique placeholder name.
     *
     * @param string $hint  Column the value belongs to, used to keep SQL readable
     * @param mixed  $value Value to bind
     *
     * @return string The generated placeholder name
     */
    private function bind(string $hint, mixed $value): string
    {
        // The hint is always an identifier this class already validated, so the
        // only character needing replacement is the qualifier dot.
        $name = 'p' . (++self::$parameterSequence) . '_' . strtr($hint, '.', '_');

        $this->bindings[$name] = $value;

        return $name;
    }

    /**
     * Reject anything that is not a bare identifier.
     *
     * Accepts `column`, `table.column` and `*`, which covers every identifier
     * this builder needs to emit.
     *
     * @param string $identifier Candidate identifier
     *
     * @throws \InvalidArgumentException When the identifier is not well formed
     */
    private function assertIdentifier(string $identifier): string
    {
        // Applications reuse the same handful of column names on every request,
        // so the pattern is matched once per distinct identifier rather than
        // once per call.
        if (isset(self::$validIdentifiers[$identifier])) {
            return self::$validIdentifiers[$identifier];
        }

        $trimmed = trim($identifier);

        if ($trimmed === '*') {
            return self::$validIdentifiers[$identifier] = '*';
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $trimmed) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s"', $identifier));
        }

        return self::$validIdentifiers[$identifier] = $trimmed;
    }

    /**
     * Render the SELECT statement.
     */
    protected function buildSelect(): string
    {
        $sql = 'SELECT ' . implode(', ', $this->select) . ' FROM ' . $this->table;
        $sql .= $this->buildWhere();

        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }

        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null) {
            // MySQL rejects OFFSET without LIMIT, so supply the documented maximum.
            $sql .= ($this->limit === null ? ' LIMIT 18446744073709551615' : '') . ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * Render the COUNT statement.
     */
    protected function buildCount(): string
    {
        return 'SELECT COUNT(*) as aggregate FROM ' . $this->table . $this->buildWhere();
    }

    /**
     * Render the WHERE clause, including its leading keyword.
     */
    protected function buildWhere(): string
    {
        $rendered = self::renderConditions($this->conditions);

        return $rendered === '' ? '' : ' WHERE ' . $rendered;
    }
}
