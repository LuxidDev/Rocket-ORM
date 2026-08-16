<?php

declare(strict_types=1);

namespace Rocket\ORM;

/**
 * Outcome of an {@see Entity::create()} call.
 *
 * Carries either the persisted entity or the validation errors that stopped it,
 * so callers branch on the result instead of on a boolean plus a second lookup.
 *
 * @package Rocket\ORM
 */
class CreateResult
{
    /**
     * @param Entity|null                       $entity The persisted entity, or null on failure
     * @param array<string, list<string>>|null   $errors Validation errors keyed by property
     */
    public function __construct(
        public readonly ?Entity $entity = null,
        public readonly ?array $errors = null,
    ) {
    }

    /**
     * Check whether the entity was persisted.
     */
    public function succeeded(): bool
    {
        return $this->entity !== null;
    }

    /**
     * Check whether the entity was rejected.
     */
    public function failed(): bool
    {
        return $this->entity === null;
    }

    /**
     * Get the persisted entity, or null on failure.
     */
    public function getEntity(): ?Entity
    {
        return $this->entity;
    }

    /**
     * Get the validation errors keyed by property.
     *
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors ?? [];
    }

    /**
     * Get the first error message, or null when there was none.
     */
    public function firstError(): ?string
    {
        foreach ($this->errors() as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return null;
    }

    /**
     * Encode the validation errors as JSON.
     */
    public function getErrorsAsJson(): string
    {
        return (string) json_encode($this->errors());
    }
}
