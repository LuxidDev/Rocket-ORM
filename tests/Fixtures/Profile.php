<?php

namespace Rocket\Tests\Fixtures;

use Rocket\ORM\Entity;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Column;
use Rocket\Attributes\Relations\BelongsTo;

#[EntityAttr(table: 'profiles')]
class Profile extends Entity
{
  #[Column(primary: true, autoIncrement: true)]
  public int $id = 0;

  #[Column]
  public string $bio = '';

  #[Column]
  public string $avatar = '';

  #[Column]
  public int $user_id = 0;

  #[BelongsTo(User::class, 'user_id', 'id')]
  protected $user;
}
