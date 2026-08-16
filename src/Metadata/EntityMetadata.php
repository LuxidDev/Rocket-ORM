<?php

namespace Rocket\Metadata;

use Rocket\Attributes\Column;
use Rocket\Attributes\Entity as EntityAttribute;
use Rocket\Attributes\Relations\HasOne;
use Rocket\Attributes\Relations\HasMany;
use Rocket\Attributes\Relations\BelongsTo;
use ReflectionClass;

class EntityMetadata
{
    protected string $className;
    protected string $tableName;
    protected string $primaryKey = 'id';
    protected array $columns = [];
    protected array $relations = [];
    protected bool $hasAutoIncrement = false;

    /**
     * Database column name keyed by entity property name.
     *
     * The hot paths — hydrating a row, serializing an entity, diffing changes —
     * used to walk the ColumnMetadata objects and call a getter per field per
     * row. These flat maps are built once so those loops are plain array reads.
     *
     * @var array<string, string>
     */
    protected array $propertyToColumn = [];

    /**
     * Entity property name keyed by database column name.
     *
     * @var array<string, string>
     */
    protected array $columnToProperty = [];

    /**
     * Column name keyed by property name, excluding hidden columns.
     *
     * @var array<string, string>
     */
    protected array $visibleColumns = [];

    /**
     * Default values keyed by property name, for columns that declare one.
     *
     * @var array<string, mixed>
     */
    protected array $defaults = [];

    /**
     * Validation rules keyed by property name, for columns that have any.
     *
     * @var array<string, list<object>>
     */
    protected array $rulesByProperty = [];

    /**
     * Property name backing the primary key column.
     */
    protected string $primaryProperty = 'id';

    public function __construct(string $className)
    {
        $this->className = $className;

        // One reflection for both passes; building it twice doubled the cost of
        // first touching an entity.
        $reflection = new ReflectionClass($className);

        $this->parseAttributes($reflection);
        $this->parseRelations($reflection);
        $this->buildLookups();
    }

    /**
     * Flatten the column metadata into maps the hot paths can index directly.
     */
    protected function buildLookups(): void
    {
        foreach ($this->columns as $column) {
            $property = $column->getProperty();
            $name = $column->getName();

            $this->propertyToColumn[$property] = $name;
            $this->columnToProperty[$name] = $property;

            if (!$column->isHidden()) {
                $this->visibleColumns[$property] = $name;
            }

            $default = $column->getDefault();

            if ($default !== null) {
                $this->defaults[$property] = $default;
            }

            $rules = $column->getRules();

            if ($rules !== []) {
                $this->rulesByProperty[$property] = $rules;
            }

            if ($name === $this->primaryKey) {
                $this->primaryProperty = $property;
            }
        }
    }

    /**
     * Get the column name for each property.
     *
     * @return array<string, string>
     */
    public function propertyToColumn(): array
    {
        return $this->propertyToColumn;
    }

    /**
     * Get the property name for each column.
     *
     * @return array<string, string>
     */
    public function columnToProperty(): array
    {
        return $this->columnToProperty;
    }

    /**
     * Get the column name for each property that is not hidden.
     *
     * @return array<string, string>
     */
    public function visibleColumns(): array
    {
        return $this->visibleColumns;
    }

    /**
     * Get the declared default value for each property that has one.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return $this->defaults;
    }

    /**
     * Get the validation rules for each property that has any.
     *
     * @return array<string, list<object>>
     */
    public function rulesByProperty(): array
    {
        return $this->rulesByProperty;
    }

    /**
     * Get the property backing the primary key column.
     */
    public function getPrimaryProperty(): string
    {
        return $this->primaryProperty;
    }

    protected function parseAttributes(ReflectionClass $reflection): void
    {
        // Parse entity attribute
        $entityAttributes = $reflection->getAttributes(EntityAttribute::class);
        if (!empty($entityAttributes)) {
            $entityAttribute = $entityAttributes[0]->newInstance();
            $this->tableName = $entityAttribute->getTable();
        } else {
            // Default table name from class name
            $this->tableName = strtolower($reflection->getShortName()) . 's';
        }

        // Parse properties
        foreach ($reflection->getProperties() as $property) {
            $columnAttributes = $property->getAttributes(Column::class);
            if (!empty($columnAttributes)) {
                // Create ColumnMetadata
                $columnMetadata = new ColumnMetadata();
                $columnMetadata->setProperty($property->getName());

                // Configure from Column attribute
                $columnAttr = $columnAttributes[0]->newInstance();
                $columnAttr->configure($columnMetadata);

                // Parse validation rules
                $this->parseValidationRules($property, $columnMetadata);

                $this->columns[] = $columnMetadata;

                if ($columnMetadata->isPrimary()) {
                    $this->primaryKey = $columnMetadata->getName();
                }

                if ($columnMetadata->isAutoIncrement()) {
                    $this->hasAutoIncrement = true;
                }
            }
        }
    }

    protected function parseRelations(ReflectionClass $reflection): void
    {
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes();

            foreach ($attributes as $attribute) {
                $attributeName = $attribute->getName();

                if ($attributeName === HasOne::class) {
                    $relation = $attribute->newInstance();
                    $relatedClass = $relation->getRelatedClass();
                    $foreignKey = $relation->getForeignKey() ?? $this->getDefaultForeignKey($relatedClass);
                    $localKey = $relation->getLocalKey() ?? 'id';

                    $this->relations[] = new RelationMetadata(
                        $property->getName(),
                        'hasOne',
                        $relatedClass,
                        $foreignKey,
                        $localKey
                    );
                } elseif ($attributeName === HasMany::class) {
                    $relation = $attribute->newInstance();
                    $relatedClass = $relation->getRelatedClass();
                    $foreignKey = $relation->getForeignKey() ?? $this->getDefaultForeignKey($this->className);
                    $localKey = $relation->getLocalKey() ?? 'id';

                    $this->relations[] = new RelationMetadata(
                        $property->getName(),
                        'hasMany',
                        $relatedClass,
                        $foreignKey,
                        $localKey
                    );
                } elseif ($attributeName === BelongsTo::class) {
                    $relation = $attribute->newInstance();
                    $relatedClass = $relation->getRelatedClass();
                    $foreignKey = $relation->getForeignKey() ?? $this->getDefaultForeignKey($relatedClass);
                    $ownerKey = $relation->getOwnerKey() ?? 'id';

                    $this->relations[] = new RelationMetadata(
                        $property->getName(),
                        'belongsTo',
                        $relatedClass,
                        $foreignKey,
                        null,
                        $ownerKey
                    );
                }
            }
        }
    }

    protected function parseValidationRules(\ReflectionProperty $property, ColumnMetadata $columnMetadata): void
    {
        $attributes = $property->getAttributes();

        foreach ($attributes as $attribute) {
            $attributeName = $attribute->getName();

            // Check if it's a validation rule
            if (strpos($attributeName, 'Rocket\\Attributes\\Rules\\') === 0) {
                $rule = $attribute->newInstance();

                // Attributes cannot see the property they decorate, so rules that
                // need the mapping are handed it here.
                if ($rule instanceof \Rocket\Attributes\Rules\ColumnAware) {
                    $rule->setColumn($columnMetadata->getName(), $property->getName());
                }

                $columnMetadata->addRule($rule);
            }
        }
    }

    protected function getDefaultForeignKey(string $class): string
    {
        $parts = explode('\\', $class);
        $className = end($parts);
        return strtolower($className) . '_id';
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function getPrimaryKey(): string
    {
        return $this->primaryKey;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getRelations(): array
    {
        return $this->relations;
    }

    public function hasAutoIncrement(): bool
    {
        return $this->hasAutoIncrement;
    }
}
