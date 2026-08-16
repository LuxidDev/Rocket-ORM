<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Attribute;
use Rocket\ORM\Entity;

/**
 * Requires the value to be present.
 *
 * `0` and `"0"` count as present; only null, the empty string, a whitespace-only
 * string and the empty array are treated as missing.
 *
 * @package Rocket\Attributes\Rules
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Required implements Rule
{
    /**
     * Message reported when the value is missing.
     */
    protected string $message = 'This field is required.';

    /**
     * @param string|null $message Custom failure message
     */
    public function __construct(?string $message = null)
    {
        if ($message !== null) {
            $this->message = $message;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validate(mixed $value, Entity $entity): bool
    {
        return match (true) {
            $value === null => false,
            is_string($value) => trim($value) !== '',
            is_array($value) => $value !== [],
            default => true,
        };
    }

    /**
     * {@inheritDoc}
     */
    public function getMessage(): string
    {
        return $this->message;
    }
}
