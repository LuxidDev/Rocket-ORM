<?php

namespace Rocket\Migration;

use Rocket\Connection\Connection;

class Schema
{
  protected Connection $db;
  protected string $table;
  protected array $columns = [];
  protected array $foreignKeys = [];
  protected array $indexes = [];
  protected bool $isAlter = false;

  /**
   * @param Connection $db      Connection the DDL runs against
   * @param string     $table   Table being created or altered
   * @param bool       $isAlter Whether this is an ALTER rather than a CREATE
   *
   * @throws \InvalidArgumentException When the table name is not a valid identifier
   */
  public function __construct(Connection $db, string $table, bool $isAlter = false)
  {
    $this->db = $db;
    $this->table = Connection::assertIdentifier($table);
    $this->isAlter = $isAlter;
  }

  /**
   * Add an auto-incrementing ID column
   */
  public function id(string $name = 'id'): Column
  {
    $column = Column::id($name);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add a string column
   */
  public function string(string $name): Column
  {
    $column = Column::string($name);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add a text column
   */
  public function text(string $name): Column
  {
    $column = Column::text($name);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add an integer column
   */
  public function integer(string $name): Column
  {
    $column = Column::integer($name);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add a float column
   */
  public function float(string $name): Column
  {
    $column = Column::float($name);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add a decimal column
   */
  public function decimal(string $name, int $total = 10, int $places = 2): Column
  {
    $column = Column::decimal($name, $total, $places);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add a boolean column
   */
  public function boolean(string $name): Column
  {
    $column = Column::boolean($name);
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add timestamp columns (created_at, updated_at)
   */
  public function timestamps(): void
  {
    $timestamps = Column::timestamps();
    foreach ($timestamps as $column) {
      $this->columns[] = $column;
    }
  }

  /**
   * Add soft deletes column
   */
  public function softDeletes(): Column
  {
    $column = Column::softDeletes();
    $this->columns[] = $column;
    return $column;
  }

  /**
   * Add a foreign key constraint
   */
  public function foreign(string $column): ForeignKey
  {
    $foreignKey = new ForeignKey($column);
    $this->foreignKeys[] = $foreignKey;
    return $foreignKey;
  }

  /**
   * Drop a column (for alter context)
   */
  public function dropColumn(string $name): self
  {
    $sql = "ALTER TABLE {$this->table} DROP COLUMN {$name}";
    $this->db->execute($sql);
    return $this;
  }

  /**
   * Execute the schema changes
   */
  public function create(): void
  {
    if ($this->isAlter) {
      $this->alter();
    } else {
      $this->createTable();
    }

    // Add foreign keys after table creation (only for new tables)
    if (!$this->isAlter) {
      foreach ($this->foreignKeys as $fk) {
        $this->addForeignKeyConstraint($fk);
      }
    }
  }

  protected function alter(): void
  {
    foreach ($this->columns as $column) {
      $sql = "ALTER TABLE {$this->table} ADD COLUMN " . $this->buildColumnDefinition($column);
      $this->db->execute($sql);
    }
  }

  /**
   * Create the table.
   *
   * The storage engine and charset are stated explicitly: utf8mb4 is the only
   * MySQL charset that covers the whole Unicode range, and leaving it to the
   * server default is how a schema ends up unable to store an emoji.
   */
  protected function createTable(): void
  {
    $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (\n";

    // Add columns
    $columnDefs = [];
    foreach ($this->columns as $column) {
      $columnDefs[] = $this->buildColumnDefinition($column);
    }

    // Add primary keys
    $primaryKeys = $this->getPrimaryKeys();
    if (!empty($primaryKeys)) {
      $columnDefs[] = "PRIMARY KEY (" . implode(', ', $primaryKeys) . ")";
    }

    // Add unique constraints
    $uniqueConstraints = $this->getUniqueConstraints();
    foreach ($uniqueConstraints as $constraint) {
      $columnDefs[] = "UNIQUE KEY {$constraint['name']} ({$constraint['columns']})";
    }

    // Add indexes
    $indexes = $this->getIndexes();
    foreach ($indexes as $index) {
      $columnDefs[] = "INDEX {$index['name']} ({$index['columns']})";
    }

    $sql .= implode(",\n", $columnDefs);
    $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $this->db->execute($sql);
  }

  /**
   * Render a single column definition.
   *
   * @param Column $column Column to render
   *
   * @throws \InvalidArgumentException When the column name is not a valid identifier
   */
  protected function buildColumnDefinition(Column $column): string
  {
    $def = Connection::assertIdentifier($column->getName()) . ' ' . $column->getType();
    $options = $column->getOptions();

    if (isset($options['nullable']) && $options['nullable']) {
      $def .= " NULL";
    } else {
      $def .= " NOT NULL";
    }

    if (isset($options['default'])) {
      if ($options['default'] === 'CURRENT_TIMESTAMP') {
        $def .= " DEFAULT CURRENT_TIMESTAMP";
      } else {
        // Defaults cannot be bound as parameters in DDL, so the literal is
        // quoted by the driver rather than interpolated raw.
        $def .= " DEFAULT " . $this->db->getPdo()->quote((string) $options['default']);
      }
    }

    if (isset($options['auto_increment']) && $options['auto_increment']) {
      $def .= " AUTO_INCREMENT";
    }

    return $def;
  }

  protected function getPrimaryKeys(): array
  {
    $primaryKeys = [];
    foreach ($this->columns as $column) {
      $options = $column->getOptions();
      if (isset($options['primary']) && $options['primary']) {
        $primaryKeys[] = $column->getName();
      }
    }
    return $primaryKeys;
  }

  protected function getUniqueConstraints(): array
  {
    $unique = [];
    $i = 0;
    foreach ($this->columns as $column) {
      $options = $column->getOptions();
      if (isset($options['unique']) && $options['unique']) {
        $i++;
        $unique[] = [
          'name' => "{$this->table}_{$column->getName()}_unique",
          'columns' => $column->getName()
        ];
      }
    }
    return $unique;
  }

  protected function getIndexes(): array
  {
    $indexes = [];
    $i = 0;
    foreach ($this->columns as $column) {
      $options = $column->getOptions();
      if (isset($options['index']) && $options['index']) {
        $i++;
        $indexes[] = [
          'name' => "{$this->table}_{$column->getName()}_index",
          'columns' => $column->getName()
        ];
      }
    }
    return $indexes;
  }

  protected function addForeignKeyConstraint(ForeignKey $fk): void
  {
    $constraintName = "fk_{$this->table}_{$fk->getColumn()}";
    $sql = "ALTER TABLE {$this->table} ADD CONSTRAINT {$constraintName} ";
    $sql .= "FOREIGN KEY ({$fk->getColumn()}) REFERENCES {$fk->getOn()}({$fk->getReferences()})";

    if ($fk->getOnDelete()) {
      $sql .= " ON DELETE {$fk->getOnDelete()}";
    }

    if ($fk->getOnUpdate()) {
      $sql .= " ON UPDATE {$fk->getOnUpdate()}";
    }

    $this->db->execute($sql);
  }
}
