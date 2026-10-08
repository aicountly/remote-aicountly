<?php

namespace Config;

use App\Support\ConsoleDatabaseDetails;
use App\Support\ConsoleDatabaseDetailsException;
use CodeIgniter\Database\Config;
use CodeIgniter\Database\Exceptions\DatabaseException;

/**
 * Database configuration.
 *
 * AICOUNTLY Remote is PostgreSQL-only. The `remote_` table prefix is part of
 * the table names themselves (see the migrations) rather than `DBPrefix`, so
 * that raw SQL, psql sessions and the migration files all read the same.
 */
class Database extends Config
{
    /**
     * The directory that holds the Migrations and Seeds directories.
     */
    public string $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

    /**
     * Lets you choose which connection group to use if no other is specified.
     */
    public string $defaultGroup = 'default';

    /**
     * @var array<string, mixed>
     */
    public array $default = [
        'DSN'      => '',
        'hostname' => 'localhost',
        'username' => '',
        'password' => '',
        'database' => '',
        'DBDriver' => 'Postgre',
        'DBPrefix' => '',
        'pConnect' => false,
        'DBDebug'  => true,
        'charset'  => 'utf8',
        'swapPre'  => '',
        'encrypt'  => false,
        'compress' => false,
        'strictOn' => false,
        'failover' => [],
        'port'     => 5432,
    ];

    /**
     * Used by the CLI-based tests and by `php spark db:seed` under CI_ENVIRONMENT=testing.
     *
     * @var array<string, mixed>
     */
    public array $tests = [
        'DSN'         => '',
        'hostname'    => '127.0.0.1',
        'username'    => '',
        'password'    => '',
        'database'    => '',
        'DBDriver'    => 'Postgre',
        'DBPrefix'    => '',
        'pConnect'    => false,
        'DBDebug'     => true,
        'charset'     => 'utf8',
        'swapPre'     => '',
        'encrypt'     => false,
        'compress'    => false,
        'strictOn'    => false,
        'failover'    => [],
        'port'        => 5432,
        'foreignKeys' => true,
        'busyTimeout' => 1000,
        'dateFormat'  => [
            'date'     => 'Y-m-d',
            'datetime' => 'Y-m-d H:i:s',
            'time'     => 'H:i:s',
        ],
    ];

    public function __construct()
    {
        parent::__construct();

        // The test suite runs against `tests`; everything else against `default`.
        if (ENVIRONMENT === 'testing') {
            $this->defaultGroup = 'tests';

            return;
        }

        // Which database and which login: Console's SaaS Database Details when CONSOLE_API_URL and
        // CONSOLE_DB_DETAILS_KEY are both set (App\Support\ConsoleDatabaseDetails), otherwise the classic
        // database.default.database / .username from .env (local development). Everything else - hostname,
        // password, port, driver - is always local: Console never holds a password.
        if (ConsoleDatabaseDetails::isConfigured()) {
            // database.default.database / .username / .DSN are NOT consulted here (BaseConfig may have loaded
            // them into $default already; applyTo() replaces both and clears the DSN), so a stale value left in
            // .env cannot win. Every consumer that boots CodeIgniter - db_connect(), models, spark commands, the
            // health check - gets the resolved pair from this one place.
            try {
                $this->default = ConsoleDatabaseDetails::applyTo($this->default);
            } catch (ConsoleDatabaseDetailsException $e) {
                // No connection is ever attempted with a blank or stale name/user, and never silently with the
                // .env ones. Callers already treat a DatabaseException as "database unavailable"; the category
                // rides along as the previous exception.
                throw new DatabaseException($e->getMessage(), 0, $e);
            }

            return;
        }

        // Console is a pair of settings. With only one of them, and no database named locally either, there is
        // nothing to connect to: say so now, by name, instead of letting the driver fail on an empty database name
        // (the usual cause is the key put under another variable name).
        $unused = ConsoleDatabaseDetails::diagnoseUnused($this->default);
        if ($unused !== null) {
            throw new DatabaseException($unused['hint'], 0, new ConsoleDatabaseDetailsException($unused['hint'], $unused['reason']));
        }
    }
}
