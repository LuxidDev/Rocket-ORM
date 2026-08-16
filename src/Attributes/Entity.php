<?php

namespace Rocket\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Entity
{
  protected string $table;

  public function __construct(?string $table = null)
  {
    $this->table = $table;
  }

  public function getTable(): string
  {
    return $this->table;
  }
}
