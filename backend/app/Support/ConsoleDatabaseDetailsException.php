<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Console could not give Remote usable database details.
 *
 * The message says what to fix and never contains CONSOLE_DB_DETAILS_KEY.
 * `category` is one of ConsoleDatabaseDetails::categories(): a fixed, secret-free name for WHY, so the
 * health endpoint, the 503 answer and `spark remote:db-check` can say what to do without
 * echoing anything from the exception. Config\Database turns it into a CodeIgniter DatabaseException (this
 * one stays as its previous), so every existing "database unavailable" path handles it.
 */
final class ConsoleDatabaseDetailsException extends RuntimeException
{
    public function __construct(string $message, public readonly string $category = 'console_unexpected_answer')
    {
        parent::__construct($message);
    }
}
