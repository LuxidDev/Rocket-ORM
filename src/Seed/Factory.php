<?php

namespace Rocket\Seed;

use Rocket\ORM\Entity;

abstract class Factory
{
  protected string $entityClass;
  protected int $count = 1;
  protected array $states = [];
  protected array $attributes = [];

  /**
   * Constructor
   */
  public function __construct(?string $entityClass = null)
  {
    if ($entityClass !== null) {
      $this->entityClass = $entityClass;
    } else {
      $this->entityClass = static::getEntityClass();
    }
  }

  /**
   * Create a new factory instance
   */
  public static function new(): self
  {
    return new static();
  }

  /**
   * Get the entity class for this factory
   * Must be implemented by child classes
   */
  protected static function getEntityClass(): string
  {
    throw new \RuntimeException('getEntityClass() must be implemented in factory subclass');
  }

  public function count(int $count): self
  {
    $this->count = $count;
    return $this;
  }

  public function state(array $state): self
  {
    $this->states[] = $state;
    return $this;
  }

  /**
   * Create entities and return them
   * 
   * @return Entity|array Returns a single entity if count is 1, otherwise an array
   */
  public function create(array $attributes = [])
  {
    $this->attributes = $attributes;
    $results = [];

    for ($i = 0; $i < $this->count; $i++) {
      $entity = $this->make();
      $entity->save();
      $results[] = $entity;
    }

    return $this->count === 1 ? $results[0] : $results;
  }

  public function make(array $attributes = []): Entity
  {
    $this->attributes = $attributes;
    $data = array_merge($this->definition(), $attributes);

    // Apply states
    foreach ($this->states as $state) {
      $data = array_merge($data, $state);
    }

    // Apply custom attributes
    $data = array_merge($data, $this->attributes);

    $entity = new $this->entityClass();
    $entity->load($data);

    return $this->entityClass::create($data);
  }

  abstract protected function definition(): array;
}
