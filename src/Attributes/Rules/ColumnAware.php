<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

/**
 * Implemented by validation rules that need to know which column they guard.
 *
 * PHP attributes cannot see the property they decorate, so the column name is
 * injected by the metadata parser once the mapping is known. Without it a rule
 * has to guess, and {@see Unique} previously guessed by scanning for the first
 * property carrying the attribute — which meant a second unique column silently
 * validated against the first one's data.
 *
 * @package Rocket\Attributes\Rules
 */
interface ColumnAware
{
    /**
     * Tell the rule which database column it guards.
     *
     * @param string $column   Database column name
     * @param string $property Entity property name
     */
    public function setColumn(string $column, string $property): void;
}
