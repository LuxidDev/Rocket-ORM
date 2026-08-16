<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Attribute;
use Rocket\ORM\Entity;

/**
 * Requires a maximum size.
 *
 * Strings are measured in characters, numbers by value and arrays by count.
 * Presence is {@see Required}'s job, so a null or empty-string value passes.
 *
 * @package Rocket\Attributes\Rules
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Max implements Rule
{
    /**
     * Upper bound.
     */
    protected int $max;

    /**
     * Message reported when the value is too large.
     */
    protected string $message = 'This field must not exceed {max} characters.';

    /**
     * @param int         $max     Upper bound
     * @param string|null $message Custom failure message
     */
    public function __construct(int $max, ?string $message = null)
    {
        $this->max = $max;

        if ($message !== null) {
            $this->message = $message;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function validate(mixed $value, Entity $entity): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return match (true) {
            // Multibyte aware: a length rule counts characters, not bytes.
            is_string($value) => mb_strlen($value) <= $this->max,
            is_int($value) || is_float($value) => $value <= $this->max,
            is_array($value) => count($value) <= $this->max,
            is_numeric($value) => (float) $value <= $this->max,
            default => false,
        };
    }

    /**
     * {@inheritDoc}
     */
    public function getMessage(): string
    {
        return str_replace('{max}', (string) $this->max, $this->message);
    }
}
