<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Attribute;
use Rocket\Connection\Connection;
use Rocket\ORM\Entity;

/**
 * Requires the value to be unused by any other row.
 *
 * When the entity already exists its own row is excluded, so re-saving a record
 * without changing the unique column does not fail its own check.
 *
 * @package Rocket\Attributes\Rules
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Unique implements Rule, ColumnAware
{
    /**
     * Table to search, or null to use the entity's own table.
     */
    protected ?string $table;

    /**
     * Column this rule guards, injected by the metadata parser.
     */
    protected ?string $column = null;

    /**
     * Message reported when the value is taken.
     */
    protected string $message = 'This value has already been taken.';

    /**
     * @param string|null $table   Table to search, defaults to the entity's table
     * @param string|null $message Custom failure message
     */
    public function __construct(?string $table = null, ?string $message = null)
    {
        $this->table = $table;

        if ($message !== null) {
            $this->message = $message;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function setColumn(string $column, string $property): void
    {
        $this->column = $column;
    }

    /**
     * Check whether the value is free.
     *
     * @param mixed  $value  Value being validated
     * @param Entity $entity Entity being validated
     *
     * @throws \RuntimeException When the rule was never told which column it guards
     */
    public function validate(mixed $value, Entity $entity): bool
    {
        // Presence is Required's job; an absent value is vacuously unique.
        if ($value === null || $value === '') {
            return true;
        }

        if ($this->column === null) {
            throw new \RuntimeException(sprintf(
                'The Unique rule on %s was not bound to a column.',
                $entity::class
            ));
        }

        $table = Connection::assertIdentifier($this->table ?? $entity::tableName());
        $column = Connection::assertIdentifier($this->column);

        $sql = sprintf('SELECT COUNT(*) AS aggregate FROM %s WHERE %s = :value', $table, $column);
        $params = ['value' => $value];

        // Exclude this row so an update does not collide with itself.
        $primaryKey = $entity::primaryKey();
        $identifier = $entity->getKey();

        if (!$entity->isNew() && $identifier !== null) {
            $sql .= sprintf(' AND %s != :identifier', Connection::assertIdentifier($primaryKey));
            $params['identifier'] = $identifier;
        }

        $result = Connection::getInstance()->query($sql, $params);

        return (int) ($result[0]['aggregate'] ?? 0) === 0;
    }

    /**
     * Get the failure message.
     */
    public function getMessage(): string
    {
        return $this->message;
    }
}
