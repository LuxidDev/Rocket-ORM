<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Rocket\ORM\Entity;

/**
 * Contract every validation rule attribute implements.
 *
 * The entity is always passed, even by rules that ignore it, so the caller never
 * has to branch on which arity a given rule happens to declare.
 *
 * @package Rocket\Attributes\Rules
 */
interface Rule
{
    /**
     * Check whether a value satisfies this rule.
     *
     * @param mixed  $value  Value being validated
     * @param Entity $entity Entity the value belongs to
     */
    public function validate(mixed $value, Entity $entity): bool;

    /**
     * Get the message reported when the rule fails.
     */
    public function getMessage(): string;
}
