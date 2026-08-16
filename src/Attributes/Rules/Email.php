<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Attribute;
use Rocket\ORM\Entity;

/**
 * Requires the value to be a syntactically valid email address.
 *
 * Presence is {@see Required}'s job, so a null or empty value passes.
 *
 * @package Rocket\Attributes\Rules
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Email implements Rule
{
    /**
     * Message reported when the value is not an email address.
     */
    protected string $message = 'This field must be a valid email address.';

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
        if ($value === null || $value === '') {
            return true;
        }

        return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * {@inheritDoc}
     */
    public function getMessage(): string
    {
        return $this->message;
    }
}
