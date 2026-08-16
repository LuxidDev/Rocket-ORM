<?php

namespace Rocket\Tests\Fixtures;

use Rocket\ORM\Entity;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Column;
use Rocket\Attributes\Relations\BelongsTo;

#[EntityAttr(table: 'posts')]
class Post extends Entity
{
  #[Column(primary: true, autoIncrement: true)]
  public int $id = 0;

  #[Column]
  public string $title = '';

  #[Column]
  public string $content = '';

  #[Column]
  public int $user_id = 0;

  #[BelongsTo(User::class, 'user_id', 'id')]
  protected $author;
}
