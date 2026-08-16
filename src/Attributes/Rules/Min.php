<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Attribute;
use Rocket\ORM\Entity;

/**
 * Requires a minimum size.
 *
 * Strings are measured in characters, numbers by value and arrays by count.
 * Presence is {@see Required}'s job, so a null or empty-string value passes —
 * but `0` is a real value and is compared, not skipped.
 *
 * @package Rocket\Attributes\Rules
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Min implements Rule
{
    /**
     * Lower bound.
     */
    protected int $min;

    /**
     * Message reported when the value is too small.
     */
    protected string $message = 'This field must be at least {min} characters.';

    /**
     * @param int         $min     Lower bound
     * @param string|null $message Custom failure message
     */
    public function __construct(int $min, ?string $message = null)
    {
        $this->min = $min;

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
            is_string($value) => mb_strlen($value) >= $this->min,
            is_int($value) || is_float($value) => $value >= $this->min,
            is_array($value) => count($value) >= $this->min,
            is_numeric($value) => (float) $value >= $this->min,
            default => false,
        };
    }

    /**
     * {@inheritDoc}
     */
    public function getMessage(): string
    {
        return str_replace('{min}', (string) $this->min, $this->message);
    }
}
