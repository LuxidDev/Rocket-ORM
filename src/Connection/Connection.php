<?php

declare(strict_types=1);

namespace Rocket\Connection;

use PDO;
use PDOException;
use PDOStatement;

/**
 * Singleton PDO wrapper.
 *
 * Emulated prepares are disabled so the driver sends parameters out of band
 * rather than interpolating them client side. That keeps integer and null types
 * intact and removes a class of injection that survives naive escaping.
 *
 * Table and column names cannot be bound as parameters, so every identifier that
 * reaches SQL through this class is validated first.
 *
 * @package Rocket\Connection
 */
class Connection
{
    /**
     * The shared connection.
     */
    protected static ?Connection $instance = null;

    /**
     * Configuration to open the shared connection from, on first use.
     *
     * @var array<string, mixed>|null
     */
    protected static ?array $pendingConfig = null;

    /**
     * The underlying PDO handle.
     */
    protected PDO $pdo;

    /**
     * Connection configuration.
     *
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @param array{dsn?: string, user?: string, password?: string, options?: array<int, mixed>} $config
     */
    protected function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Get the shared connection, creating it from $config on first call.
     *
     * @param array<string, mixed>|null $config Configuration, required on first call
     *
     * @throws \RuntimeException When no connection exists and no config was given
     */
    public static function getInstance(?array $config = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $config ??= self::$pendingConfig;

        if ($config === null) {
            throw new \RuntimeException('Connection not configured. Call configure() first.');
        }

        return self::$instance = new self($config);
    }

    /**
     * Record the configuration without opening a connection.
     *
     * Connecting eagerly meant an application whose database was unreachable
     * could not serve even the routes that never touch it, and the failure
     * surfaced as a stack trace during boot. The socket is opened the first
     * time a query actually needs it.
     *
     * @param array<string, mixed> $config Connection configuration
     */
    public static function configure(array $config): void
    {
        self::$pendingConfig = $config;
        self::$instance = null;
    }

    /**
     * Open the shared connection immediately.
     *
     * @param array<string, mixed> $config Connection configuration
     */
    public static function initialize(array $config): void
    {
        self::$pendingConfig = $config;
        self::$instance = new self($config);
    }

    /**
     * Check whether a connection has been configured, opened or not.
     */
    public static function isConfigured(): bool
    {
        return self::$instance !== null || self::$pendingConfig !== null;
    }

    /**
     * Drop the shared connection, mainly so tests can start clean.
     */
    public static function reset(): void
    {
        self::$instance = null;
        self::$pendingConfig = null;
    }

    /**
     * Check whether a shared connection exists.
     */
    public static function isInitialized(): bool
    {
        return self::$instance !== null;
    }

    /**
     * Open the PDO handle.
     *
     * @throws ConnectionException When the driver refuses the connection
     */
    protected function connect(): void
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Real prepared statements: parameters never reach the SQL text.
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ] + ($this->config['options'] ?? []);

        try {
            $this->pdo = new PDO(
                $this->config['dsn'] ?? '',
                $this->config['user'] ?? '',
                $this->config['password'] ?? '',
                $options
            );
        } catch (PDOException $e) {
            // Re-thrown without the DSN so credentials cannot reach a log or an
            // error page; the driver exception is kept as `previous` for anyone
            // debugging with a full trace.
            throw new ConnectionException(
                'Database connection failed: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }

    /**
     * Get the underlying PDO handle.
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Insert a row.
     *
     * @param string               $table Table name
     * @param array<string, mixed> $data  Column/value pairs
     *
     * @throws \InvalidArgumentException When the table, a column, or the data is invalid
     */
    public function insert(string $table, array $data): bool
    {
        if ($data === []) {
            throw new \InvalidArgumentException('Cannot insert an empty row.');
        }

        $table = self::assertIdentifier($table);
        $columns = self::assertIdentifiers(array_keys($data));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );

        $this->run($sql, $data);

        return true;
    }

    /**
     * Update rows matching a set of conditions.
     *
     * @param string               $table Table name
     * @param array<string, mixed> $data  Column/value pairs to assign
     * @param array<string, mixed> $where Column/value pairs combined with AND
     *
     * @throws \InvalidArgumentException When an identifier is invalid or $where is empty
     */
    public function update(string $table, array $data, array $where): bool
    {
        if ($data === []) {
            return true;
        }

        if ($where === []) {
            // An unconstrained UPDATE would rewrite the whole table.
            throw new \InvalidArgumentException('Refusing to update without a WHERE clause.');
        }

        $table = self::assertIdentifier($table);
        $columns = self::assertIdentifiers(array_keys($data));
        $conditions = self::assertIdentifiers(array_keys($where));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode(', ', array_map(static fn (string $c): string => $c . ' = :' . $c, $columns)),
            implode(' AND ', array_map(static fn (string $c): string => $c . ' = :where_' . $c, $conditions))
        );

        $bindings = $data;

        foreach ($where as $column => $value) {
            $bindings['where_' . $column] = $value;
        }

        $this->run($sql, $bindings);

        return true;
    }

    /**
     * Delete rows matching a set of conditions.
     *
     * @param string               $table Table name
     * @param array<string, mixed> $where Column/value pairs combined with AND
     *
     * @throws \InvalidArgumentException When an identifier is invalid or $where is empty
     */
    public function delete(string $table, array $where): bool
    {
        if ($where === []) {
            // An unconstrained DELETE would empty the table.
            throw new \InvalidArgumentException('Refusing to delete without a WHERE clause.');
        }

        $table = self::assertIdentifier($table);
        $conditions = self::assertIdentifiers(array_keys($where));

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode(' AND ', array_map(static fn (string $c): string => $c . ' = :' . $c, $conditions))
        );

        $this->run($sql, $where);

        return true;
    }

    /**
     * Run a SELECT and return every row.
     *
     * @param string               $sql    SQL with named placeholders
     * @param array<string, mixed> $params Placeholder values
     *
     * @return list<array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * Run a statement and return the number of affected rows.
     *
     * @param string               $sql    SQL with named placeholders
     * @param array<string, mixed> $params Placeholder values
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * Prepare, bind and execute a statement, returning it for reading.
     *
     * Values are bound individually rather than passed to `execute()` so nulls,
     * booleans and integers keep their type once emulation is off. Placeholder
     * keys may be written with or without a leading colon.
     *
     * This method executes; callers must not execute the returned statement
     * again. Doing so ran every insert, update and delete twice.
     *
     * @param string               $sql    SQL with named placeholders
     * @param array<string, mixed> $params Placeholder values
     */
    protected function run(string $sql, array $params): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $name => $value) {
            $statement->bindValue(
                is_int($name) ? $name + 1 : ':' . ltrim((string) $name, ':'),
                $value,
                match (true) {
                    is_null($value) => PDO::PARAM_NULL,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_int($value) => PDO::PARAM_INT,
                    default => PDO::PARAM_STR,
                }
            );
        }

        $statement->execute();

        return $statement;
    }

    /**
     * Get the id generated by the most recent insert.
     */
    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Open a transaction.
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit the open transaction.
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Roll the open transaction back.
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Run a callback inside a transaction, rolling back on failure.
     *
     * @template T
     *
     * @param callable(self): T $callback Work to perform
     *
     * @return T
     *
     * @throws \Throwable Whatever the callback threw, after rolling back
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e;
        }
    }

    /**
     * Reject anything that is not a bare SQL identifier.
     *
     * @param string $identifier Candidate identifier
     *
     * @throws \InvalidArgumentException When the identifier is not well formed
     */
    public static function assertIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid SQL identifier "%s"', $identifier));
        }

        return $identifier;
    }

    /**
     * Validate a list of identifiers.
     *
     * @param list<string> $identifiers Candidate identifiers
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException When any identifier is not well formed
     */
    public static function assertIdentifiers(array $identifiers): array
    {
        return array_map(self::assertIdentifier(...), $identifiers);
    }
}
