<?php

declare(strict_types=1);

namespace Rocket\Tests\Fixtures;

use Rocket\Attributes\Column;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Rules\Unique;
use Rocket\ORM\Entity;

/**
 * Entity with two unique columns.
 *
 * Exists to prove each Unique rule is bound to the column it decorates rather
 * than to whichever unique column appears first on the class.
 *
 * @package Rocket\Tests\Fixtures
 */
#[EntityAttr(table: 'accounts')]
class MultiUniqueEntity extends Entity
{
    #[Column(primary: true, autoIncrement: true)]
    public int $id = 0;

    #[Column]
    #[Unique]
    public string $email = '';

    #[Column]
    #[Unique]
    public string $username = '';
}
