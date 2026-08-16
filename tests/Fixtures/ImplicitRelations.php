<?php

declare(strict_types=1);

namespace Rocket\Tests\Fixtures;

use Rocket\Attributes\Column;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Relations\BelongsTo;
use Rocket\Attributes\Relations\HasMany;
use Rocket\Attributes\Relations\HasOne;
use Rocket\ORM\Entity;

/**
 * Entity whose relations omit their foreign keys.
 *
 * Exists so the derived-key path is covered: `hasMany` keys off this entity's
 * own name, while `hasOne` and `belongsTo` key off the related one.
 *
 * @package Rocket\Tests\Fixtures
 */
#[EntityAttr(table: 'authors')]
class ImplicitRelations extends Entity
{
    #[Column(primary: true, autoIncrement: true)]
    public int $id = 0;

    #[HasMany(Post::class)]
    protected $posts;

    #[HasOne(Profile::class)]
    protected $profile;

    #[BelongsTo(User::class)]
    protected $owner;
}
