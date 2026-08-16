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
     * Rendered AND conditions.
     *
     * @var list<string>
     */
    protected array $where = [];

    /**
     * Rendered OR conditions.
     *
     * @var list<string>
     */
    protected array $orWhere = [];

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
                $this->where[] = $nested;
            }

            return $this;
        }

        [$operator, $value] = $this->normalizeComparison(func_num_args(), $operator, $value);

        $this->where[] = $this->condition($column, $operator, $value);

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
                $this->orWhere[] = $nested;
            }

            return $this;
        }

        [$operator, $value] = $this->normalizeComparison(func_num_args(), $operator, $value);

        $this->orWhere[] = $this->condition($column, $operator, $value);

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
            $this->where[] = '1 = 0';

            return $this;
        }

        $placeholders = [];

        foreach ($values as $value) {
            $placeholders[] = ':' . $this->bind($column, $value);
        }

        $this->where[] = sprintf('%s IN (%s)', $column, implode(', ', $placeholders));

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

        $this->where[] = sprintf('%s NOT IN (%s)', $column, implode(', ', $placeholders));

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

        $this->where[] = sprintf(
            '%s BETWEEN :%s AND :%s',
            $column,
            $this->bind($column, $from),
            $this->bind($column, $to)
        );

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
        $this->where[] = $this->assertIdentifier($column) . ' IS NULL';

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
        $this->where[] = $this->assertIdentifier($column) . ' IS NOT NULL';

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
        $this->where[] = '(' . $sql . ')';
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
        $rows = Connection::getInstance()->query($this->buildSelect(), $this->bindings);
        $entities = [];

        foreach ($rows as $row) {
            $entity = new $this->entityClass();
            $entity->load($row);
            $entities[] = $entity;
        }

        return $entities;
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
     * Get the rendered AND conditions.
     *
     * @return list<string>
     */
    public function getWhereConditions(): array
    {
        return $this->where;
    }

    /**
     * Get the rendered OR conditions.
     *
     * @return list<string>
     */
    public function getOrWhereConditions(): array
    {
        return $this->orWhere;
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

        $and = $nested->getWhereConditions();
        $or = $nested->getOrWhereConditions();

        if ($and !== [] && $or !== []) {
            return '(' . implode(' AND ', $and) . ' AND (' . implode(' OR ', $or) . '))';
        }

        if ($and !== []) {
            return '(' . implode(' AND ', $and) . ')';
        }

        if ($or !== []) {
            return '(' . implode(' OR ', $or) . ')';
        }

        return null;
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

        return sprintf('%s %s :%s', $column, $operator, $this->bind($column, $value));
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

        $operator = strtoupper(trim((string) $operator));

        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s"', $operator));
        }

        return [$operator, $value];
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
        $name = sprintf(
            'p%d_%s',
            ++self::$parameterSequence,
            preg_replace('/[^A-Za-z0-9_]/', '_', $hint)
        );

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
        $identifier = trim($identifier);

        if ($identifier === '*') {
            return $identifier;
        }

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s"', $identifier));
        }

        return $identifier;
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
        $conditions = [];

        if ($this->where !== []) {
            $conditions[] = implode(' AND ', $this->where);
        }

        if ($this->orWhere !== []) {
            $or = implode(' OR ', $this->orWhere);
            $conditions[] = $conditions === [] ? $or : '(' . $or . ')';
        }

        return $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);
    }
}
