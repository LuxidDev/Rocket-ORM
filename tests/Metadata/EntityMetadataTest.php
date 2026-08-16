<?php

declare(strict_types=1);

namespace Rocket\Tests\Metadata;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rocket\Attributes\Rules\Email;
use Rocket\Attributes\Rules\Required;
use Rocket\Tests\Fixtures\ImplicitRelations;
use Rocket\Tests\Fixtures\TestUser;
use Rocket\Tests\Fixtures\User;

/**
 * Tests for entity metadata parsing and the lookup maps built from it.
 *
 * @package Rocket\Tests\Metadata
 */
final class EntityMetadataTest extends TestCase
{
    #[Test]
    public function it_reads_the_table_and_primary_key(): void
    {
        $metadata = TestUser::getMetadata();

        $this->assertSame('users', $metadata->getTableName());
        $this->assertSame('id', $metadata->getPrimaryKey());
        $this->assertSame('id', $metadata->getPrimaryProperty());
        $this->assertTrue($metadata->hasAutoIncrement());
    }

    #[Test]
    public function it_maps_properties_to_columns_both_ways(): void
    {
        $metadata = TestUser::getMetadata();

        $this->assertSame('email', $metadata->propertyToColumn()['email']);
        $this->assertSame('email', $metadata->columnToProperty()['email']);
        $this->assertCount(count($metadata->getColumns()), $metadata->propertyToColumn());
    }

    #[Test]
    public function hidden_columns_are_excluded_from_the_visible_map(): void
    {
        $visible = TestUser::getMetadata()->visibleColumns();

        $this->assertArrayHasKey('email', $visible);
        $this->assertArrayNotHasKey('password', $visible);
    }

    #[Test]
    public function it_groups_rules_by_property(): void
    {
        $rules = TestUser::getMetadata()->rulesByProperty();

        $this->assertArrayHasKey('email', $rules);
        $this->assertArrayNotHasKey('id', $rules);

        $types = array_map(static fn (object $r): string => $r::class, $rules['email']);

        $this->assertContains(Required::class, $types);
        $this->assertContains(Email::class, $types);
    }

    #[Test]
    public function it_keeps_explicit_relation_keys(): void
    {
        $relations = [];

        foreach (User::getMetadata()->getRelations() as $relation) {
            $relations[$relation->getName()] = $relation;
        }

        $this->assertSame('hasMany', $relations['posts']->getType());
        $this->assertSame('user_id', $relations['posts']->getForeignKey());
        $this->assertSame('hasOne', $relations['profile']->getType());
    }

    #[Test]
    public function it_derives_a_missing_relation_key_from_the_right_class(): void
    {
        $relations = [];

        foreach (ImplicitRelations::getMetadata()->getRelations() as $relation) {
            $relations[$relation->getName()] = $relation;
        }

        // hasMany points back at this entity, so the key names this class.
        $this->assertSame('implicitrelations_id', $relations['posts']->getForeignKey());

        // hasOne and belongsTo key off the related class instead.
        $this->assertSame('profile_id', $relations['profile']->getForeignKey());
        $this->assertSame('user_id', $relations['owner']->getForeignKey());
        $this->assertSame('belongsTo', $relations['owner']->getType());
    }

    #[Test]
    public function metadata_is_built_once_per_class(): void
    {
        $this->assertSame(TestUser::getMetadata(), TestUser::getMetadata());
    }
}
