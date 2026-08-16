<?php

declare(strict_types=1);

namespace Rocket\Tests\Attributes;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rocket\Attributes\Rules\Email;
use Rocket\Attributes\Rules\In;
use Rocket\Attributes\Rules\Max;
use Rocket\Attributes\Rules\Min;
use Rocket\Attributes\Rules\Required;
use Rocket\Attributes\Rules\Rule;
use Rocket\Attributes\Rules\Unique;
use Rocket\ORM\Entity;
use Rocket\Tests\Fixtures\MultiUniqueEntity;
use Rocket\Tests\Fixtures\TestUser;

/**
 * Tests for the attribute-driven validation rules.
 *
 * @package Rocket\Tests\Attributes
 */
final class RulesTest extends TestCase
{
    /**
     * An entity instance to pass as the validation subject.
     */
    private Entity $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = new TestUser();
    }

    #[Test]
    public function required_rejects_null_and_blank_values(): void
    {
        $rule = new Required();

        $this->assertFalse($rule->validate(null, $this->entity));
        $this->assertFalse($rule->validate('', $this->entity));
        $this->assertFalse($rule->validate('   ', $this->entity));
        $this->assertFalse($rule->validate([], $this->entity));
    }

    #[Test]
    public function required_accepts_zero(): void
    {
        // Regression: the check used empty(), which treats 0 and "0" as missing.
        $rule = new Required();

        $this->assertTrue($rule->validate(0, $this->entity));
        $this->assertTrue($rule->validate('0', $this->entity));
        $this->assertTrue($rule->validate(false, $this->entity));
    }

    #[Test]
    public function min_counts_characters_not_bytes(): void
    {
        // "héllo" is 5 characters but 6 bytes in UTF-8.
        $this->assertFalse((new Min(6))->validate('héllo', $this->entity));
        $this->assertTrue((new Min(5))->validate('héllo', $this->entity));
    }

    #[Test]
    public function max_counts_characters_not_bytes(): void
    {
        $this->assertTrue((new Max(5))->validate('héllo', $this->entity));
        $this->assertFalse((new Max(4))->validate('héllo', $this->entity));
    }

    #[Test]
    public function min_compares_numbers_by_value(): void
    {
        $this->assertFalse((new Min(18))->validate(17, $this->entity));
        $this->assertTrue((new Min(18))->validate(18, $this->entity));
    }

    #[Test]
    public function min_still_compares_zero(): void
    {
        // Regression: empty() short-circuited on 0, so a lower bound above zero
        // silently passed.
        $this->assertFalse((new Min(1))->validate(0, $this->entity));
    }

    #[Test]
    public function min_skips_absent_values(): void
    {
        $this->assertTrue((new Min(8))->validate(null, $this->entity));
        $this->assertTrue((new Min(8))->validate('', $this->entity));
    }

    #[Test]
    public function min_interpolates_its_bound_into_the_message(): void
    {
        $this->assertStringContainsString('8', (new Min(8))->getMessage());
    }

    #[Test]
    public function email_accepts_a_valid_address(): void
    {
        $this->assertTrue((new Email())->validate('jhay@luxid.dev', $this->entity));
    }

    #[Test]
    public function email_rejects_an_invalid_address(): void
    {
        $this->assertFalse((new Email())->validate('not-an-email', $this->entity));
        $this->assertFalse((new Email())->validate(['a@b.c'], $this->entity));
    }

    #[Test]
    public function in_compares_strictly(): void
    {
        // Regression: a loose comparison let "1" satisfy a rule allowing only 1.
        $rule = new In([1, 2, 3]);

        $this->assertTrue($rule->validate(1, $this->entity));
        $this->assertFalse($rule->validate('1', $this->entity));
    }

    #[Test]
    public function in_exposes_its_allowed_values(): void
    {
        $this->assertSame(['draft', 'published'], (new In(['draft', 'published']))->getAllowed());
    }

    #[Test]
    public function unique_refuses_to_run_before_it_is_bound_to_a_column(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not bound to a column');

        (new Unique())->validate('a@b.c', $this->entity);
    }

    #[Test]
    public function unique_skips_absent_values_without_touching_the_database(): void
    {
        $this->assertTrue((new Unique())->validate(null, $this->entity));
        $this->assertTrue((new Unique())->validate('', $this->entity));
    }

    #[Test]
    public function metadata_binds_each_unique_rule_to_its_own_column(): void
    {
        // Regression: the rule scanned for the first property carrying a Unique
        // attribute, so a second unique column validated against the first.
        $metadata = MultiUniqueEntity::getMetadata();
        $bound = [];

        foreach ($metadata->getColumns() as $column) {
            foreach ($column->getRules() as $rule) {
                if ($rule instanceof Unique) {
                    $bound[] = $column->getName();
                }
            }
        }

        $this->assertSame(['email', 'username'], $bound);
    }

    #[Test]
    public function every_rule_implements_the_shared_contract(): void
    {
        foreach ([new Required(), new Min(1), new Max(1), new Email(), new In([]), new Unique()] as $rule) {
            $this->assertInstanceOf(Rule::class, $rule);
        }
    }
}
