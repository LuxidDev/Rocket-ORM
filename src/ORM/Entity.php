<?php

namespace Rocket\ORM;

use JsonSerializable;
use Rocket\Metadata\EntityMetadata;
use Rocket\Metadata\RelationMetadata;
use Rocket\Connection\Connection;
use Rocket\Query\QueryBuilder;
use Rocket\Relations\HasOne;
use Rocket\Relations\HasMany;
use Rocket\Relations\BelongsTo;

abstract class Entity implements JsonSerializable
{
    /**
     * Cache for entity metadata
     */
    protected static array $metadata = [];

    /**
     * Whether the entity is new (not saved to database)
     */
    protected bool $isNew = true;

    /**
     * Original attribute values before changes
     */
    protected array $original = [];

    /**
     * Validation errors
     */
    protected array $errors = [];

    /**
     * Loaded relations
     */
    protected array $relations = [];

    public function __construct()
    {
        // Initialize any default values
        $metadata = static::getMetadata();
        foreach ($metadata->getColumns() as $column) {
            $property = $column->getProperty();
            if ($column->getDefault() !== null && !isset($this->$property)) {
                $this->$property = $column->getDefault();
            }
        }
    }

    public static function create(array $data): CreateResult
    {
        $entity = new static();
        $metadata = static::getMetadata();

        // Only set properties that are database columns
        foreach ($metadata->getColumns() as $column) {
            $property = $column->getProperty();

            // Skip system-managed fields
            if ($column->isPrimary() && $column->isAutoIncrement()) {
                continue;
            }

            if ($column->isAutoCreate()) {
                continue;
            }

            // Set from input data if present
            if (array_key_exists($property, $data)) {
                $entity->$property = $data[$property];
            }
        }

        // Validate using existing rules
        if (!$entity->validate()) {
            return new CreateResult(null, $entity->getErrors());
        }

        // Save automatically
        if (!$entity->save()) {
            return new CreateResult(null, ['save' => ['Failed to save entity']]);
        }

        return new CreateResult($entity);
    }

    public function update(array $data): bool
    {
        $metadata = static::getMetadata();

        foreach ($metadata->getColumns() as $column) {
            $property = $column->getProperty();

            // Never update primary key or auto fields
            if ($column->isPrimary() && $column->isAutoIncrement()) {
                continue;
            }

            if ($column->isAutoCreate() || $column->isAutoUpdate()) {
                continue;
            }

            if (array_key_exists($property, $data)) {
                $this->$property = $data[$property];
            }
        }

        if (!$this->validate()) {
            return false;
        }

        return $this->save();
    }

    /**
     * Get entity metadata (parsed from attributes)
     */
    public static function getMetadata(): EntityMetadata
    {
        $class = static::class;

        if (!isset(self::$metadata[$class])) {
            self::$metadata[$class] = new EntityMetadata($class);
        }

        return self::$metadata[$class];
    }

    /**
     * Get the table name for this entity
     */
    public static function tableName(): string
    {
        return static::getMetadata()->getTableName();
    }

    /**
     * Get the primary key column name
     */
    public static function primaryKey(): string
    {
        return static::getMetadata()->getPrimaryKey();
    }

    /**
     * Get the database connection
     */
    public static function connection(): Connection
    {
        return Connection::getInstance();
    }

    /**
     * Truncate the table (delete all records and reset auto-increment)
     *
     * @return bool
     */
    public static function truncate(): bool
    {
        $tableName = static::tableName();
        $connection = self::connection();

        // MySQL truncate
        $sql = "TRUNCATE TABLE {$tableName}";

        try {
            $connection->execute($sql);
            return true;
        } catch (\Exception $e) {
            // Fallback to DELETE for databases that don't support TRUNCATE
            $connection->execute("DELETE FROM {$tableName}");
            // Reset auto-increment
            $connection->execute("ALTER TABLE {$tableName} AUTO_INCREMENT = 1");
            return true;
        }
    }

    /**
     * Read a property, tolerating uninitialized typed properties.
     *
     * Typed properties without a default are in an "uninitialized" state that
     * throws on read, so every internal access goes through here.
     *
     * @param string $property Property name
     */
    protected function readProperty(string $property): mixed
    {
        if (!property_exists($this, $property)) {
            return null;
        }

        return isset($this->$property) ? $this->$property : null;
    }

    /**
     * Load a database row into the entity.
     *
     * Rows are keyed by column name, which is not always the property name, so
     * the mapping is resolved through the entity metadata before assignment.
     *
     * @param array<string, mixed> $data Row keyed by column name
     */
    public function load(array $data): self
    {
        $metadata = static::getMetadata();

        foreach ($metadata->getColumns() as $column) {
            $name = $column->getName();

            if (array_key_exists($name, $data)) {
                $this->{$column->getProperty()} = $data[$name];
            }
        }

        // Preserve any extra selected columns that are not mapped, such as
        // aggregates produced by a custom select.
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }

        return $this;
    }

    /**
     * Get the value an attribute held when it was last loaded or saved.
     *
     * @param string $attribute Property name
     */
    public function getOriginal(string $attribute): mixed
    {
        return $this->original[$attribute] ?? null;
    }

    /**
     * Check whether an attribute has changed since it was last synced.
     *
     * @param string $attribute Property name
     */
    public function isDirty(string $attribute): bool
    {
        return array_key_exists($attribute, $this->original)
            && $this->original[$attribute] !== $this->readProperty($attribute);
    }

    /**
     * Get every changed attribute, keyed by database column name.
     *
     * Keys are column names rather than property names because the result feeds
     * straight into an UPDATE statement.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach (static::getMetadata()->getColumns() as $column) {
            $property = $column->getProperty();

            if (!array_key_exists($property, $this->original)) {
                continue;
            }

            $current = $this->readProperty($property);

            if ($this->original[$property] !== $current) {
                $dirty[$column->getName()] = $current;
            }
        }

        return $dirty;
    }

    /**
     * Save the entity
     */
    public function save(): bool
    {
        $this->beforeSave();

        if ($this->validate()) {
            if ($this->isNew) {
                $result = $this->performInsert();
            } else {
                $result = $this->performUpdate();
            }

            if ($result) {
                $this->afterSave();
                $this->isNew = false;
                $this->syncOriginal();
                return true;
            }
        }

        return false;
    }

    /**
     * Delete all records from the table
     *
     * @return bool
     */
    public static function deleteAll(): bool
    {
        $tableName = static::tableName();
        return self::connection()->execute("DELETE FROM {$tableName}") !== false;
    }

    /**
     * Get the count of records in the table
     *
     * @return int
     */
    public static function count(): int
    {
        return static::query()->count();
    }

    /**
     * Check if any records exist in the table
     *
     * @return bool
     */
    public static function exists(): bool
    {
        return static::count() > 0;
    }

    /**
     * Get the first record in the table
     *
     * @return static|null
     */
    public static function first(): ?static
    {
        return static::query()->orderBy(static::primaryKey(), 'ASC')->first();
    }

    /**
     * Get the last record in the table
     *
     * @return static|null
     */
    public static function last(): ?static
    {
        return static::query()->orderBy(static::primaryKey(), 'DESC')->first();
    }

    /**
     * Get random records
     *
     * @param int $limit
     * @return array
     */
    public static function random(int $limit = 1): array
    {
        return static::query()
            ->inRandomOrder()
            ->limit(max(1, $limit))
            ->all();
    }

    /**
     * Perform insert operation
     */
    protected function performInsert(): bool
    {
        $metadata = static::getMetadata();
        $columns = $metadata->getColumns();
        $data = [];

        foreach ($columns as $column) {
            $property = $column->getProperty();

            // Skip if property doesn't exist
            if (!property_exists($this, $property)) {
                continue;
            }

            $value = $this->readProperty($property);

            // Skip auto-generated columns (like auto-increment ID)
            if ($column->isAutoIncrement() && (empty($value) || $value === 0)) {
                continue;
            }

            // Skip auto-create timestamps
            if ($column->isAutoCreate() && empty($value)) {
                continue;
            }

            $data[$column->getName()] = $value;
        }

        $connection = self::connection();
        $result = $connection->insert(static::tableName(), $data);

        if ($result && $metadata->hasAutoIncrement()) {
            $pk = static::primaryKey();
            $this->$pk = $connection->lastInsertId();
        }

        return $result;
    }

    /**
     * Perform update operation
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true;
        }

        $pk = static::primaryKey();

        return self::connection()->update(
            static::tableName(),
            $dirty,
            [$pk => $this->$pk]
        );
    }

    /**
     * Delete the entity
     */
    public function delete(): bool
    {
        $this->beforeDelete();

        $pk = static::primaryKey();
        $result = self::connection()->delete(
            static::tableName(),
            [$pk => $this->$pk]
        );

        if ($result) {
            $this->afterDelete();
        }

        return $result;
    }

    /**
     * Sync original values (after save)
     */
    protected function syncOriginal(): void
    {
        $metadata = static::getMetadata();

        foreach ($metadata->getColumns() as $column) {
            $property = $column->getProperty();
            $this->original[$property] = $this->readProperty($property);
        }
    }

    /**
     * Validate the entity
     */
    public function validate(): bool
    {
        $this->errors = [];
        $metadata = static::getMetadata();

        foreach ($metadata->getColumns() as $column) {
            $property = $column->getProperty();
            $value = $this->readProperty($property);
            $rules = $column->getRules();

            foreach ($rules as $rule) {
                if (!$rule->validate($value, $this)) {
                    $this->errors[$property][] = $rule->getMessage();
                }
            }
        }

        return empty($this->errors);
    }

    /**
     * Get validation errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get first error for an attribute
     */
    public function getFirstError(string $attribute): ?string
    {
        return $this->errors[$attribute][0] ?? null;
    }

    /**
     * Check if entity has errors
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Find an entity by ID
     */
    public static function find(int $id): ?static
    {
        $pk = static::primaryKey();
        return static::findOne([$pk => $id]);
    }

    /**
     * Find one entity by conditions
     */
    public static function findOne(array $conditions): ?static
    {
        $query = static::query();

        foreach ($conditions as $column => $value) {
            $query->where($column, '=', $value);
        }

        $result = $query->first();

        if ($result) {
            $result->isNew = false;
            $result->syncOriginal();
        }

        return $result;
    }

    /**
     * Find all entities matching conditions
     */
    public static function findAll(array $conditions = [], array $orderBy = [], int $limit = null): array
    {
        $query = static::query();

        foreach ($conditions as $column => $value) {
            $query->where($column, '=', $value);
        }

        foreach ($orderBy as $column => $direction) {
            $query->orderBy($column, $direction);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        $results = $query->all();

        foreach ($results as $result) {
            $result->isNew = false;
            $result->syncOriginal();
        }

        return $results;
    }

    /**
     * Get a query builder for this entity
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::class);
    }

    // Lifecycle Hooks (override in child classes)
    protected function beforeSave(): void {}
    protected function afterSave(): void {}
    protected function beforeDelete(): void {}
    protected function afterDelete(): void {}

    /**
     * Load a relation
     */
    protected function loadRelation(RelationMetadata $relation)
    {
        $type = $relation->getType();
        $relatedClass = $relation->getRelatedClass();

        switch ($type) {
            case 'hasOne':
                $rel = new HasOne($this, $relatedClass, $relation->getForeignKey(), $relation->getLocalKey());
                return $rel->get();

            case 'hasMany':
                $rel = new HasMany($this, $relatedClass, $relation->getForeignKey(), $relation->getLocalKey());
                return $rel->get();

            case 'belongsTo':
                $rel = new BelongsTo($this, $relatedClass, $relation->getForeignKey(), $relation->getOwnerKey());
                return $rel->get();
        }

        return null;
    }

    /**
     * Magic getter for computed properties and relations
     */
    public function __get(string $name)
    {
        // Check for computed property
        $method = 'get' . ucfirst($name);
        if (method_exists($this, $method)) {
            return $this->$method();
        }

        // Check for cached relation
        if (isset($this->relations[$name])) {
            return $this->relations[$name];
        }

        // Load relation
        $metadata = static::getMetadata();
        foreach ($metadata->getRelations() as $relation) {
            if ($relation->getName() === $name) {
                $related = $this->loadRelation($relation);
                $this->relations[$name] = $related;
                return $related;
            }
        }

        return null;
    }

    /**
     * Magic isset for computed properties and relations
     */
    public function __isset(string $name): bool
    {
        $method = 'get' . ucfirst($name);
        if (method_exists($this, $method)) {
            return true;
        }

        $metadata = static::getMetadata();
        foreach ($metadata->getRelations() as $relation) {
            if ($relation->getName() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert entity to array
     */
    public function toArray(): array
    {
        $metadata = static::getMetadata();
        $data = [];

        foreach ($metadata->getColumns() as $column) {
            $property = $column->getProperty();

            if (!$column->isHidden()) {
                $data[$column->getName()] = $this->readProperty($property);
            }
        }

        return $data;
    }

    /**
     * Serialize the entity to JSON
     * This method is called automatically by json_encode()
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
