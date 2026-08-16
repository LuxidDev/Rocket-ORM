# Changelog

## 0.3.0

Pre-release. Several fixes change behaviour on purpose; they are listed first.

### Breaking

- **Identifiers are validated before they reach SQL.** `where()`, `orderBy()`,
  `select()`, `groupBy()` and the `whereIn`/`whereBetween` family reject
  anything that is not a bare `column` or `table.column`. Code that passed
  request input straight to `orderBy()` now throws instead of building an
  injectable query — use `QueryFilter::sort()` with an allowlist.
- **Comparison operators are allowlisted.** `where($column, $operator, $value)`
  accepts only `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`, `IS`
  and `IS NOT`.
- **`Connection::update()` and `delete()` refuse an empty `$where`.** An
  unconstrained statement would rewrite or empty the whole table.
- **Emulated prepares are disabled.** Parameters are sent out of band, so
  integers and nulls keep their type. Code relying on PDO's client-side
  interpolation may need adjusting.
- **`Entity::getDirty()` returns database column names**, not property names,
  because the result feeds directly into an UPDATE.
- **`In` compares strictly.** `"1"` no longer satisfies a rule allowing `1`.
- **`Required` treats `0` and `"0"` as present.** It used `empty()`.
- **Validation rules implement `Rocket\Attributes\Rules\Rule`.** Custom rules
  must match `validate(mixed $value, Entity $entity): bool`.
- **Minimum PHP is now 8.1.**

### Fixed

- Nested `where(Closure)` bindings no longer overwrite the outer query's.
  Sub-builders restarted placeholder numbering at zero, so merging their
  bindings silently replaced values and changed what the query matched.
- Two `whereIn()` calls on the same column keep distinct bindings.
- `#[Unique]` checks the column it decorates. It scanned the class for the first
  property carrying the attribute, so a second unique column validated against
  the first one's data.
- `#[Unique]` no longer fatals on update; it read the entity's protected
  `$isNew` property from outside the class.
- Reading an uninitialized typed property no longer throws during `validate()`,
  `getDirty()`, `syncOriginal()` or `toArray()`.
- `Entity::load()` maps rows by column name, so a column whose name differs from
  its property is no longer dropped.
- `Entity::random()` works; it passed `RAND()` to `orderBy()`, which is now a
  validated identifier. Use `inRandomOrder()`.
- `Min` and `Max` count characters rather than bytes, so a multibyte string is
  measured correctly.
- Message placeholders are filled positionally, so `max:50` renders `50`
  instead of leaving `:max` in the text.
- `OFFSET` is no longer emitted without a `LIMIT`, which MySQL rejects.

### Added

- `Connection::configure()` records settings without connecting; the socket
  opens on first use, so an application that never queries never pays for it.
- `Connection::transaction()`, `isConfigured()` and `reset()`.
- `QueryFilter::sort()` with a column allowlist — the safe way to accept a
  `?sort=` parameter. `QueryFilter::apply()` escapes LIKE metacharacters and
  `paginate()` clamps the page size.
- `QueryBuilder::whereNotIn()`, `whereBetween()`, `whereLike()`, `whereRaw()`,
  `groupBy()`, `inRandomOrder()`, `pluck()`, `exists()` and `toSql()`.
- `Entity::isNew()`, `setIsNew()` and `getKey()`.
- `CreateResult::errors()` and `firstError()`.
- Tables are created as InnoDB with `utf8mb4` / `utf8mb4_unicode_ci`.
- A PHPUnit suite covering query generation, binding isolation, identifier
  guards and rule semantics.
