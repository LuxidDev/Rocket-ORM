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
    /**
     * Namespace every validation rule attribute lives under.
     */
    private const RULE_NAMESPACE = 'Rocket\\Attributes\\Rules\\';

    /**
     * Relation attribute class names mapped to the kind they declare.
     */
    private const RELATION_KINDS = [
        HasOne::class => 'hasOne',
        HasMany::class => 'hasMany',
        BelongsTo::class => 'belongsTo',
    ];

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

        $this->parse($reflection);
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

    /**
     * Read the entity attribute and walk every property once.
     *
     * The column, validation-rule and relation attributes used to be collected
     * in three separate passes, each calling `getAttributes()` on every
     * property. Reflection attribute lookups are the dominant cost of building
     * metadata, so they are gathered in a single pass and dispatched by name.
     *
     * @param ReflectionClass<object> $reflection Reflection over the entity class
     */
    protected function parse(ReflectionClass $reflection): void
    {
        $entityAttributes = $reflection->getAttributes(EntityAttribute::class);

        $this->tableName = $entityAttributes === []
            ? strtolower($reflection->getShortName()) . 's'
            : $entityAttributes[0]->newInstance()->getTable();

        foreach ($reflection->getProperties() as $property) {
            $columnAttribute = null;
            $ruleAttributes = [];
            $relationAttribute = null;
            $relationKind = null;

            foreach ($property->getAttributes() as $attribute) {
                $name = $attribute->getName();

                if ($name === Column::class) {
                    $columnAttribute ??= $attribute;
                    continue;
                }

                if (str_starts_with($name, self::RULE_NAMESPACE)) {
                    $ruleAttributes[] = $attribute;
                    continue;
                }

                $kind = self::RELATION_KINDS[$name] ?? null;

                if ($kind !== null) {
                    $relationAttribute = $attribute;
                    $relationKind = $kind;
                }
            }

            if ($columnAttribute !== null) {
                $this->addColumn($property, $columnAttribute, $ruleAttributes);
            }

            if ($relationAttribute !== null) {
                $this->addRelation($property->getName(), $relationKind, $relationAttribute->newInstance());
            }
        }
    }

    /**
     * Build the column metadata for a property and attach its rules.
     *
     * @param \ReflectionProperty                        $property        The mapped property
     * @param \ReflectionAttribute<Column>               $columnAttribute Its Column attribute
     * @param list<\ReflectionAttribute<object>>          $ruleAttributes  Its validation rule attributes
     */
    protected function addColumn(
        \ReflectionProperty $property,
        \ReflectionAttribute $columnAttribute,
        array $ruleAttributes
    ): void {
        $column = new ColumnMetadata();
        $column->setProperty($property->getName());
        $columnAttribute->newInstance()->configure($column);

        foreach ($ruleAttributes as $ruleAttribute) {
            $rule = $ruleAttribute->newInstance();

            // Attributes cannot see the property they decorate, so rules that
            // need the mapping are handed it here.
            if ($rule instanceof \Rocket\Attributes\Rules\ColumnAware) {
                $rule->setColumn($column->getName(), $property->getName());
            }

            $column->addRule($rule);
        }

        $this->columns[] = $column;

        if ($column->isPrimary()) {
            $this->primaryKey = $column->getName();
        }

        if ($column->isAutoIncrement()) {
            $this->hasAutoIncrement = true;
        }
    }

    /**
     * Record a relation declared on a property.
     *
     * @param string $property Property the relation is declared on
     * @param string $kind     `hasOne`, `hasMany` or `belongsTo`
     * @param object $relation The instantiated relation attribute
     */
    protected function addRelation(string $property, string $kind, object $relation): void
    {
        $relatedClass = $relation->getRelatedClass();

        if ($kind === 'belongsTo') {
            $this->relations[] = new RelationMetadata(
                $property,
                'belongsTo',
                $relatedClass,
                $relation->getForeignKey() ?? $this->getDefaultForeignKey($relatedClass),
                null,
                $relation->getOwnerKey() ?? 'id'
            );

            return;
        }

        // hasMany keys off this entity's own name; hasOne off the related one.
        $foreignKeyOwner = $kind === 'hasMany' ? $this->className : $relatedClass;

        $this->relations[] = new RelationMetadata(
            $property,
            $kind,
            $relatedClass,
            $relation->getForeignKey() ?? $this->getDefaultForeignKey($foreignKeyOwner),
            $relation->getLocalKey() ?? 'id'
        );
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
