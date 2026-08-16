<?php

namespace Rocket\Tests\Fixtures;

use Rocket\ORM\Entity;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Column;
use Rocket\Attributes\Relations\HasMany;
use Rocket\Attributes\Relations\HasOne;

#[EntityAttr(table: 'users')]
class User extends Entity
{
  #[Column(primary: true, autoIncrement: true)]
  public int $id = 0;

  #[Column]
  public string $name = '';

  #[Column]
  public string $email = '';

  #[HasMany(Post::class, 'user_id', 'id')]
  protected $posts;

  #[HasOne(Profile::class, 'user_id', 'id')]
  protected $profile;
}
