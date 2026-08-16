<?php

declare(strict_types=1);

namespace Rocket\Attributes\Rules;

use Attribute;
use Rocket\ORM\Entity;

/**
 * Requires the value to be one of a fixed set.
 *
 * Comparison is strict, so `"1"` does not satisfy a rule that allows `1`. This
 * is deliberate: a loose check on an enum-style column lets unexpected input
 * through on type juggling alone.
 *
 * @package Rocket\Attributes\Rules
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class In implements Rule
{
    /**
     * Permitted values.
     *
     * @var list<mixed>
     */
    protected array $allowed;

    /**
     * Message reported when the value is not permitted.
     */
    protected string $message = 'The selected value is invalid.';

    /**
     * @param list<mixed> $allowed Permitted values
     * @param string|null $message Custom failure message
     */
    public function __construct(array $allowed, ?string $message = null)
    {
        $this->allowed = $allowed;

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

        return in_array($value, $this->allowed, true);
    }

    /**
     * {@inheritDoc}
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * Get the permitted values.
     *
     * @return list<mixed>
     */
    public function getAllowed(): array
    {
        return $this->allowed;
    }
}
