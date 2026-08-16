<?php

namespace Rocket\Tests\Fixtures;

use Rocket\ORM\Entity;
use Rocket\Attributes\Entity as EntityAttr;
use Rocket\Attributes\Column;
use Rocket\Attributes\Rules\Required;
use Rocket\Attributes\Rules\Email;
use Rocket\Attributes\Rules\Min;

#[EntityAttr(table: 'users')]
class TestUser extends Entity
{
  #[Column(primary: true, autoIncrement: true)]
  public int $id;

  #[Column]
  #[Required]
  #[Email]
  public string $email;

  #[Column(hidden: true)]
  #[Required]
  #[Min(8)]
  public string $password;

  #[Column]
  #[Required]
  public string $firstname;

  #[Column]
  #[Required]
  public string $lastname;

  #[Column(autoCreate: true)]
  public string $created_at;

  #[Column(autoCreate: true, autoUpdate: true)]
  public string $updated_at;

  // Computed property - use a method with get prefix
  public function getDisplayName(): string
  {
    return $this->firstname . ' ' . $this->lastname;
  }

  // Check if password is hashed
  public function isPasswordHashed(): bool
  {
    return password_get_info($this->password)['algo'] !== 0;
  }

  // Lifecycle hook
  protected function beforeSave(): void
  {
    if (!empty($this->password) && !$this->isPasswordHashed()) {
      $this->password = password_hash($this->password, PASSWORD_DEFAULT);
    }
  }
}
