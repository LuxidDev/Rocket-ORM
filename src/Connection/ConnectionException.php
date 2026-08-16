<?php

declare(strict_types=1);

namespace Rocket\Connection;

use RuntimeException;

/**
 * Thrown when the database connection cannot be opened.
 *
 * A dedicated type so callers can offer connection-specific help — check the
 * DSN, start the server — without catching every RuntimeException in the call
 * stack. The driver's own PDOException is attached as the previous exception
 * rather than re-thrown, so the DSN and its credentials stay out of any handler
 * that prints the message.
 *
 * @package Rocket\Connection
 */
class ConnectionException extends RuntimeException
{
}
