<?php

namespace Rocket\ORM;

class CreateResult
{
    public function __construct(
        public readonly ?Entity $entity,
        public readonly ?array $errors = null
    ) {}

    public function succeeded(): bool
    {
        return $this->entity !== null;
    }

    public function failed(): bool
    {
        return $this->entity === null;
    }

    public function getErrorsAsJson(): string
    {
        return json_encode($this->errors ?? []);
    }

    public function getEntity(): ?Entity
    {
        return $this->entity;
    }
}
