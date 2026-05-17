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

    public function __construct(string $className)
    {
        $this->className = $className;
        $this->parseAttributes();
        $this->parseRelations();
    }

    protected function parseAttributes(): void
    {
        $reflection = new ReflectionClass($this->className);

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

    protected function parseRelations(): void
    {
        $reflection = new ReflectionClass($this->className);

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
